<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Enrichment\CompanyEnricher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Crawl a company's website for contacts + text, then hand it to the AI step.
 */
class EnrichCompanyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 170;

    public int $tries = 2;

    public array $backoff = [60];

    public int $uniqueFor = 1800;

    /** @param bool $forceAnalysis analyze even if the pool is full (manual add / "re-analyze" from the UI) */
    public function __construct(public readonly int $companyId, public readonly bool $forceAnalysis = false)
    {
        $this->onQueue('crawl');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(CompanyEnricher $enricher): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null) {
            return;
        }

        $enricher->enrich($company);

        AnalyzeCompanyJob::dispatch($company->id, $this->forceAnalysis);
    }
}
