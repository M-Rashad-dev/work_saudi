<?php

namespace App\Services\Enrichment;

use App\DTO\ContactCandidate;
use App\DTO\DiscoveredCompanyDTO;
use App\Enums\ContactLabel;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Saves contacts with the unique(type, value) rule. If a number/email already belongs to another
 * company (shared agency footer, group companies) it is left there — never stolen or duplicated.
 */
class ContactRecorder
{
    public function __construct(
        private readonly PhoneExtractor $phones,
        private readonly EmailExtractor $emails,
    ) {}

    /** @param list<ContactCandidate> $candidates @return int number of new contacts */
    public function record(Company $company, array $candidates): int
    {
        $created = 0;

        foreach ($candidates as $candidate) {
            $existing = Contact::query()
                ->where('type', $candidate->type)
                ->where('value', $candidate->value)
                ->first();

            if ($existing === null) {
                try {
                    $company->contacts()->create([
                        'type' => $candidate->type,
                        'value' => $candidate->value,
                        'label' => $candidate->label,
                        'is_mobile' => $candidate->isMobile,
                        'is_whatsapp' => $candidate->isWhatsapp,
                        'source_url' => $candidate->sourceUrl,
                    ]);
                    $created++;
                } catch (UniqueConstraintViolationException) {
                    // inserted concurrently — fine
                }

                continue;
            }

            if ($existing->company_id !== $company->id) {
                continue;
            }

            // Same company, new evidence: upgrade flags, never downgrade.
            $existing->is_whatsapp = $existing->is_whatsapp || $candidate->isWhatsapp;
            $existing->is_mobile = $existing->is_mobile || $candidate->isMobile;
            if ($existing->label === ContactLabel::General && $candidate->label !== ContactLabel::General) {
                $existing->label = $candidate->label;
            }
            if ($existing->isDirty()) {
                $existing->save();
            }
        }

        return $created;
    }

    /** Contacts a source handed us directly (e.g. OSM phone/email tags). */
    public function recordFromDiscovery(Company $company, DiscoveredCompanyDTO $dto): int
    {
        $sourceUrl = $dto->source->value;
        $candidates = [
            ...array_map(fn ($raw) => $this->phones->fromRaw($raw, $sourceUrl), $dto->phones),
            ...array_map(fn ($raw) => $this->phones->fromRaw($raw, $sourceUrl, whatsapp: true), $dto->whatsapps),
            ...array_map(fn ($raw) => $this->emails->fromRaw($raw, $sourceUrl), $dto->emails),
        ];

        return $this->record($company, array_values(array_filter($candidates)));
    }
}
