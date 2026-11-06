<?php

namespace App\Services\Enrichment;

use App\DTO\ContactCandidate;
use App\Enums\ContactLabel;
use App\Enums\ContactType;

/**
 * Finds phone numbers on a page: WhatsApp links (highest priority), tel: links, then visible text.
 * Handles +966 / 00966 / 966 / 05… / 011… / 920… and Arabic-Indic digits.
 */
class PhoneExtractor
{
    private const WHATSAPP_LINK = '#(?:wa\.me/|whatsapp\.com/send/?\?(?:.*&)?phone=|whatsapp://send/?\?(?:.*&)?phone=|wa\.link/)\+?([0-9%\s\-]{8,20})#i';

    private const HR_CONTEXT = '/(hr|human resources|recruit|career|jobs?|hiring|talent|الموارد البشرية|التوظيف|توظيف|الوظائف|وظائف)/iu';

    public function __construct(private readonly PhoneNormalizer $normalizer) {}

    /** @return list<ContactCandidate> unique by value, best evidence kept */
    public function extract(HtmlDocument $doc, bool $isCareersPage = false): array
    {
        $found = [];
        $pageLabel = $isCareersPage ? ContactLabel::Careers : ContactLabel::General;

        foreach ($doc->links() as $link) {
            $url = $link['url'];

            if (preg_match(self::WHATSAPP_LINK, $url, $m)) {
                $this->add($found, $m[1], ContactLabel::Whatsapp, $doc->url, whatsapp: true);
            } elseif (preg_match('/^(tel|callto):(.+)$/i', $url, $m)) {
                $label = preg_match(self::HR_CONTEXT, $link['text']) ? ContactLabel::Hr : $pageLabel;
                $this->add($found, $m[2], $label, $doc->url);
            }
        }

        $text = PhoneNormalizer::convertDigits($doc->visibleText());
        foreach ($this->normalizer->findInText($text) as $match) {
            // The 60 characters before the number on the same line tell us what it is for.
            $line = mb_substr($text, max(0, $match['start'] - 60), min($match['start'], 60));
            $before = mb_substr($line, (int) mb_strrpos($line, "\n"));
            $label = preg_match(self::HR_CONTEXT, $before) ? ContactLabel::Hr : $pageLabel;
            $whatsappHint = preg_match('/(whats\s?app|واتس|واتساب)/iu', $before) === 1;
            $this->add($found, $match['raw'], $whatsappHint ? ContactLabel::Whatsapp : $label, $doc->url, whatsapp: $whatsappHint);
        }

        return array_values($found);
    }

    /** Normalize a raw value given by a source (e.g. OSM phone tag). */
    public function fromRaw(string $raw, ?string $sourceUrl = null, bool $whatsapp = false): ?ContactCandidate
    {
        $phone = $this->normalizer->normalize($raw);

        return $phone === null ? null : new ContactCandidate(
            type: ContactType::Phone,
            value: $phone->e164,
            label: $whatsapp ? ContactLabel::Whatsapp : ContactLabel::General,
            isMobile: $phone->isMobile,
            isWhatsapp: $whatsapp && $phone->isMobile,
            sourceUrl: $sourceUrl,
        );
    }

    /** @param array<string, ContactCandidate> $found */
    private function add(array &$found, string $raw, ContactLabel $label, string $sourceUrl, bool $whatsapp = false): void
    {
        $phone = $this->normalizer->normalize($raw);
        if ($phone === null) {
            return;
        }

        $candidate = new ContactCandidate(
            type: ContactType::Phone,
            value: $phone->e164,
            label: $label,
            isMobile: $phone->isMobile,
            // Only a mobile can be on WhatsApp (landlines/920 never get the button).
            isWhatsapp: $whatsapp && $phone->isMobile,
            sourceUrl: $sourceUrl,
        );

        if (! isset($found[$phone->e164]) || $candidate->rank() > $found[$phone->e164]->rank()) {
            $found[$phone->e164] = $candidate;
        }
    }
}
