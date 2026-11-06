<?php

namespace App\Jobs;

use App\DTO\CompanyAnalysisInput;
use App\Enums\CompanyStatus;
use App\Exceptions\AllDriversFailedException;
use App\Models\Company;
use App\Services\Analysis\AnalysisBacklog;
use App\Services\Analysis\CompanyAnalyzer;
use App\Services\Analysis\CompanyQualifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * AI step for one enriched company → qualified / rejected.
 * Skips (leaves "enriched") when the pool already has enough qualified companies.
 */
class AnalyzeCompanyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = AnalysisBacklog::MAX_ATTEMPTS;

    public array $backoff = [300, 900];

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $companyId, public readonly bool $force = false)
    {
        $this->onQueue('llm');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(CompanyAnalyzer $analyzer, CompanyQualifier $qualifier, AnalysisBacklog $backlog): void
    {
        $company = Company::query()->find($this->companyId);
        if ($company === null || $company->status === CompanyStatus::Shown) {
            return;
        }
        if (! $this->force && ($company->analyzed_at !== null || $backlog->isPoolFull())) {
            return; // already done, or enough qualified companies waiting — save the free quota
        }

        if (! $company->contacts()->exists()) {
            $qualifier->rejectWithoutContacts($company);

            return;
        }

        $company->increment('analysis_attempts');

        try {
            $result = $analyzer->analyze(CompanyAnalysisInput::fromCompany($company, (int) config('outreach.llm.max_input_chars', 4000)));
        } catch (AllDriversFailedException $e) {
            Log::warning('Analysis failed for company', ['company' => $company->id, 'errors' => $e->errors]);

            throw $e; // retried with backoff; stays "enriched" meanwhile
        }

        $qualifier->apply($company, $result);
    }
}
