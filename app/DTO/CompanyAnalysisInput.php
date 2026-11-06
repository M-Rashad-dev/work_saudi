<?php

namespace App\DTO;

use App\Models\Company;

final readonly class CompanyAnalysisInput
{
    public function __construct(
        public string $name,
        public ?string $website = null,
        public ?string $city = null,
        public ?string $searchSnippet = null,
        public ?string $jobPostUrl = null,
        public ?string $metaDescription = null,
        public string $websiteText = '',
    ) {}

    public static function fromCompany(Company $company, int $maxChars = 4000): self
    {
        return new self(
            name: $company->name,
            website: $company->website,
            city: $company->city,
            searchSnippet: $company->source_snippet ? mb_substr($company->source_snippet, 0, 1500) : null,
            jobPostUrl: $company->job_post_url,
            metaDescription: $company->meta_description,
            websiteText: mb_substr((string) $company->website_text, 0, $maxChars),
        );
    }

    /** Everything we know, as one lowercase string (for keyword scoring). */
    public function allText(): string
    {
        return mb_strtolower(implode("\n", array_filter([
            $this->name, $this->searchSnippet, $this->metaDescription, $this->websiteText, $this->jobPostUrl,
        ])));
    }
}
