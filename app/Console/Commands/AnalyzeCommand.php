<?php

namespace App\Console\Commands;

use App\DTO\CompanyAnalysisInput;
use App\Models\Company;
use App\Services\Analysis\AnalysisBacklog;
use App\Services\Analysis\CompanyAnalyzer;
use App\Services\Analysis\CompanyQualifier;
use Illuminate\Console\Command;
use Throwable;

class AnalyzeCommand extends Command
{
    protected $signature = 'outreach:analyze
                            {company? : Company id — analyzes now and prints the result}
                            {--pending : Queue enriched companies until the pool target is reached}
                            {--driver= : Force one driver (gemini|ollama|heuristic) for this run}';

    protected $description = 'Run the AI analysis step (Gemini → Ollama → Heuristic) and qualify/reject companies';

    public function handle(CompanyAnalyzer $analyzer, CompanyQualifier $qualifier, AnalysisBacklog $backlog): int
    {
        if ($this->option('pending')) {
            $queued = $backlog->dispatchIfNeeded();
            $this->info("Pool: {$backlog->poolSize()}/{$backlog->poolTarget()} — queued {$queued} companies on the \"llm\" queue.");

            return self::SUCCESS;
        }

        $company = Company::query()->find($this->argument('company'));
        if ($company === null) {
            $this->error('Company not found. Usage: outreach:analyze {id} | --pending');

            return self::FAILURE;
        }

        $only = $this->option('driver') ? [(string) $this->option('driver')] : null;
        $this->line('Available drivers: '.implode(', ', $analyzer->availableDriverNames()).($only ? ' — forcing '.$only[0] : ''));

        if (! $company->contacts()->exists()) {
            $qualifier->rejectWithoutContacts($company);
            $this->warn('No contacts → rejected without calling the LLM.');

            return self::SUCCESS;
        }

        try {
            $result = $analyzer->analyze(CompanyAnalysisInput::fromCompany($company, (int) config('outreach.llm.max_input_chars', 4000)), $only);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $qualifier->apply($company, $result);

        $this->table(['field', 'value'], [
            ['driver', $result->driver],
            ['status', $company->status->value],
            ['relevance', $result->relevance],
            ['software company', $result->isSoftwareCompany ? 'yes' : 'no'],
            ['hiring devs', $result->isHiringDevs ? 'yes' : 'no'],
            ['reason', $result->reasonAr],
            ['description', $result->descriptionAr],
            ['services', implode('، ', $result->services)],
            ['tech', implode(', ', $result->techHints)],
            ['city', $company->city ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
