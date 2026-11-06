<?php

namespace App\DTO;

use App\Enums\CompanySource;

/**
 * A company candidate produced by a source, before it is deduped and stored.
 */
final readonly class DiscoveredCompanyDTO
{
    /**
     * @param  string|null  $domain  registrable domain (already normalized), null if no website known
     * @param  string|null  $city  Arabic display name (e.g. "الرياض")
     * @param  list<string>  $phones  raw phone strings, when the source provides them (Overpass)
     * @param  list<string>  $emails  raw emails, when the source provides them
     * @param  list<string>  $whatsapps  raw numbers explicitly tagged as WhatsApp
     */
    public function __construct(
        public string $name,
        public CompanySource $source,
        public ?string $website = null,
        public ?string $domain = null,
        public ?string $city = null,
        public ?string $snippet = null,
        public ?string $jobPostUrl = null,
        public bool $isHiringSignal = false,
        public ?string $linkedinUrl = null,
        public array $phones = [],
        public array $emails = [],
        public array $whatsapps = [],
    ) {}
}
