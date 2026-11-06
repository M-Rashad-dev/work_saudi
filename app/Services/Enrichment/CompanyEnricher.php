<?php

namespace App\Services\Enrichment;

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Discovery\CompanyTitleParser;
use App\Services\Discovery\NameNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Enriches one company from its website. Never throws: a broken site just ends up with
 * no extra contacts and an error note, and the pipeline moves on.
 */
class CompanyEnricher
{
    public function __construct(
        private readonly WebsiteCrawler $crawler,
        private readonly ContactRecorder $contacts,
        private readonly CompanyTitleParser $titles,
        private readonly NameNormalizer $names,
    ) {}

    /** @return array{contacts_added: int, pages: int, errors: list<string>} */
    public function enrich(Company $company): array
    {
        $summary = ['contacts_added' => 0, 'pages' => 0, 'errors' => []];

        if ($company->website) {
            try {
                $result = $this->crawler->crawl($company->website);
            } catch (Throwable $e) {
                Log::warning('Crawl crashed', ['company' => $company->id, 'error' => $e->getMessage()]);
                $result = null;
                $summary['errors'][] = 'crawl crashed: '.mb_substr($e->getMessage(), 0, 200);
            }

            if ($result !== null) {
                $summary['contacts_added'] = $this->contacts->record($company, $result->contacts());
                $summary['pages'] = count($result->pages);
                $summary['errors'] = [...$summary['errors'], ...$result->errors];

                $company->website_text = $result->text ?: null;
                $company->meta_description = $result->metaDescription ? mb_substr($result->metaDescription, 0, 1000) : null;
                $company->linkedin_url ??= $result->linkedinUrl;
                $this->improveName($company, $result->siteName);
                $company->enrichment = ['pages' => $result->pages, 'errors' => $result->errors, 'reachable' => $result->reachable];
            }
        } else {
            $company->enrichment = ['pages' => [], 'errors' => ['no website'], 'reachable' => false];
        }

        $company->enriched_at = now();
        if ($company->status === CompanyStatus::New) {
            $company->status = CompanyStatus::Enriched;
        }
        $company->save();

        return $summary;
    }

    /** Replace a name guessed from the domain ("Acme Soft") with og:site_name when available. */
    private function improveName(Company $company, ?string $siteName): void
    {
        $siteName = trim((string) $siteName);
        if ($siteName === '' || mb_strlen($siteName) > 60 || $company->domain === null) {
            return;
        }

        if ($company->name === $this->titles->fromDomain($company->domain)) {
            $company->name = $siteName;
            $company->normalized_name = $this->names->normalize($siteName);
        }
    }
}
