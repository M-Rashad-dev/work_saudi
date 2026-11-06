<?php

namespace App\Services\Analysis;

use App\Enums\CompanyStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\Company;

/**
 * "Only analyze as many companies as needed": the AI step stops once the pool of qualified,
 * never-shown companies reaches the target (30). This tops it up from enriched companies
 * that were skipped earlier.
 */
class AnalysisBacklog
{
    public const MAX_ATTEMPTS = 3;

    public function poolSize(): int
    {
        return Company::query()->availableForBatch()->count();
    }

    public function poolTarget(): int
    {
        return (int) config('outreach.pool_target', 30);
    }

    public function isPoolFull(): bool
    {
        return $this->poolSize() >= $this->poolTarget();
    }

    /** Dispatch analysis for waiting companies (hiring signals first). Returns how many were queued. */
    public function dispatchIfNeeded(): int
    {
        $missing = $this->poolTarget() - $this->poolSize();
        if ($missing <= 0) {
            return 0;
        }

        // Roughly half the analyzed companies qualify, so queue twice what is missing.
        $ids = Company::query()
            ->where('status', CompanyStatus::Enriched)
            ->whereNull('analyzed_at')
            ->where('analysis_attempts', '<', self::MAX_ATTEMPTS)
            ->orderByRaw('job_post_url IS NULL')
            ->orderBy('id')
            ->limit($missing * 2)
            ->pluck('id');

        $ids->each(fn (int $id) => AnalyzeCompanyJob::dispatch($id));

        return $ids->count();
    }
}
