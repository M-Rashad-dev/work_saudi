<?php

namespace App\Services\Enrichment;

use App\DTO\ContactCandidate;
use App\Enums\ContactLabel;
use App\Enums\ContactType;

/**
 * Finds emails (mailto:, visible text, Cloudflare-protected) and ranks them:
 * hr@/recruitment@/talent@ → careers@/jobs@ → info@/contact@ → the rest.
 */
class EmailExtractor
{
    private const EMAIL_BODY = '[a-z0-9][a-z0-9._%+\-]{0,63}@[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?(?:\.[a-z0-9\-]{1,63})*\.[a-z]{2,24}';

    private const EMAIL = '/'.self::EMAIL_BODY.'/i';

    private const HR = ['hr', 'humanresources', 'recruitment', 'recruiting', 'recruit', 'talent', 'talents', 'people', 'hiring'];

    private const CAREERS = ['careers', 'career', 'jobs', 'job', 'cv', 'resume', 'join', 'joinus', 'work'];

    private const GENERAL = ['info', 'contact', 'hello', 'hi', 'admin', 'office', 'sales', 'support', 'inquiries', 'enquiries'];

    /** Not real contact addresses. */
    private const JUNK = [
        '/\.(png|jpe?g|gif|webp|svg|css|js|ico)$/i', '/@\d+x\./i', '/example\.(com|org|net)$/i', '/@(sentry|wixpress|.*\.wix)\b/i',
        '/^(your|name|email|user|username|test|someone)@/i', '/@(domain|email|yourdomain|company|mail)\.com$/i',
        '/^u00[0-9a-f]{2}/i', '/no-?reply|donotreply/i',
    ];

    /** @return list<ContactCandidate> best first */
    public function extract(HtmlDocument $doc, bool $isCareersPage = false): array
    {
        $raw = [];

        foreach ($doc->links() as $link) {
            if (preg_match('/^mailto:([^?]+)/i', $link['url'], $m)) {
                $raw[] = rawurldecode($m[1]);
            }
        }
        preg_match_all(self::EMAIL, $doc->visibleText(), $m);
        $raw = [...$raw, ...$m[0], ...$doc->cloudflareEmails()];

        $found = [];
        foreach ($raw as $email) {
            if (($candidate = $this->candidate($email, $doc->url, $isCareersPage)) !== null) {
                $found[$candidate->value] ??= $candidate;
            }
        }

        return $this->sort(array_values($found));
    }

    public function fromRaw(string $email, ?string $sourceUrl = null): ?ContactCandidate
    {
        return $this->candidate($email, $sourceUrl, false);
    }

    /** @param list<ContactCandidate> $emails @return list<ContactCandidate> */
    public function sort(array $emails): array
    {
        usort($emails, fn (ContactCandidate $a, ContactCandidate $b) => $this->priority($a->value) <=> $this->priority($b->value));

        return $emails;
    }

    /** Lower = better. */
    public function priority(string $email): int
    {
        $local = preg_replace('/[^a-z]/', '', strtolower(strstr($email, '@', true) ?: ''));

        return match (true) {
            in_array($local, self::HR, true) => 0,
            in_array($local, self::CAREERS, true) => 1,
            in_array($local, self::GENERAL, true) => $local === 'info' ? 2 : 3,
            default => 4,
        };
    }

    private function candidate(string $email, ?string $sourceUrl, bool $isCareersPage): ?ContactCandidate
    {
        $email = strtolower(trim($email, " \t\n\r.,;:<>()[]\"'"));
        if (! preg_match('/^'.self::EMAIL_BODY.'$/i', $email) || strlen($email) > 191) {
            return null;
        }
        foreach (self::JUNK as $pattern) {
            if (preg_match($pattern, $email)) {
                return null;
            }
        }

        $label = match ($this->priority($email)) {
            0 => ContactLabel::Hr,
            1 => ContactLabel::Careers,
            default => $isCareersPage ? ContactLabel::Careers : ContactLabel::General,
        };

        return new ContactCandidate(type: ContactType::Email, value: $email, label: $label, sourceUrl: $sourceUrl);
    }
}
