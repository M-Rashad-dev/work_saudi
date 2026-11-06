<?php

namespace App\Services\Discovery;

use App\DTO\DiscoveredCompanyDTO;
use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Stores a discovered company without creating duplicates.
 *
 * Dedupe order: 1) registrable domain  2) normalized_name + city.
 * Existing companies are only enriched with missing data — status is never changed, so a company
 * that was shown or rejected stays that way and never comes back into a batch.
 */
class CompanyUpserter
{
    private const SNIPPET_MAX = 2000;

    public function __construct(
        private readonly NameNormalizer $names,
        private readonly DomainNormalizer $domains,
    ) {}

    /** @return array{0: Company, 1: bool} [company, wasCreated] */
    public function upsert(DiscoveredCompanyDTO $dto): array
    {
        $domain = $dto->domain ?? $this->domains->fromUrl($dto->website);
        $normalized = $this->names->normalize($dto->name);

        $existing = $this->find($domain, $normalized, $dto->city);
        if ($existing !== null) {
            $this->merge($existing, $dto, $domain);

            return [$existing, false];
        }

        try {
            $company = Company::query()->create([
                'name' => Str::limit(trim($dto->name), 250, ''),
                'normalized_name' => $normalized,
                'domain' => $domain,
                'website' => $dto->website,
                'city' => $dto->city,
                'linkedin_url' => $dto->linkedinUrl,
                'google_maps_url' => Company::mapsUrlFor($dto->name, $dto->city),
                'job_post_url' => $dto->jobPostUrl,
                'source' => $dto->source,
                'source_snippet' => $this->limitSnippet($dto->snippet),
                'status' => CompanyStatus::New,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another worker inserted the same domain between our find() and create().
            $company = Company::query()->where('domain', $domain)->firstOrFail();
            $this->merge($company, $dto, $domain);

            return [$company, false];
        }

        return [$company, true];
    }

    public function find(?string $domain, string $normalizedName, ?string $city): ?Company
    {
        if ($domain !== null && ($byDomain = Company::query()->where('domain', $domain)->first())) {
            return $byDomain;
        }

        // Same name in the same city (or one side has no city yet).
        // When we know the domain, only match name-twins that have no domain: two different
        // domains with the same name are treated as two companies.
        return Company::query()
            ->where('normalized_name', $normalizedName)
            ->when($city !== null, fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('city', $city)->orWhereNull('city')))
            ->when($domain !== null, fn (Builder $q) => $q->whereNull('domain'))
            ->orderBy('id')
            ->first();
    }

    private function merge(Company $company, DiscoveredCompanyDTO $dto, ?string $domain): void
    {
        if ($company->domain === null && $domain !== null) {
            $company->domain = $domain;
            $company->website = $dto->website;
        }

        $company->website ??= $dto->website;
        $company->linkedin_url ??= $dto->linkedinUrl;

        if ($company->city === null && $dto->city !== null) {
            $company->city = $dto->city;
            $company->google_maps_url = Company::mapsUrlFor($company->name, $dto->city);
        }

        // A hiring signal is valuable even for a company we already knew.
        if ($company->job_post_url === null && $dto->jobPostUrl !== null) {
            $company->job_post_url = $dto->jobPostUrl;
        }

        if ($dto->snippet && ! str_contains((string) $company->source_snippet, $dto->snippet)) {
            $company->source_snippet = $this->limitSnippet(trim($company->source_snippet."\n".$dto->snippet));
        }

        if ($company->isDirty()) {
            $company->save();
        }
    }

    private function limitSnippet(?string $snippet): ?string
    {
        return $snippet === null ? null : mb_substr(trim($snippet), 0, self::SNIPPET_MAX);
    }
}
