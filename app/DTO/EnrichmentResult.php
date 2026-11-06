<?php

namespace App\DTO;

final class EnrichmentResult
{
    /**
     * @param  list<ContactCandidate>  $phones
     * @param  list<ContactCandidate>  $emails
     * @param  list<array{url: string, status: string, rendered?: bool}>  $pages
     * @param  list<string>  $errors
     */
    public function __construct(
        public array $phones = [],
        public array $emails = [],
        public ?string $linkedinUrl = null,
        public ?string $metaDescription = null,
        public ?string $siteName = null,
        public string $text = '',
        public array $pages = [],
        public array $errors = [],
        public bool $reachable = false,
    ) {}

    /** @return list<ContactCandidate> */
    public function contacts(): array
    {
        return [...$this->phones, ...$this->emails];
    }
}
