<?php

namespace App\Services\Discovery;

use App\Contracts\CompanySourceInterface;
use App\Enums\SearchRunType;
use App\Jobs\EnrichCompanyJob;
use App\Models\SearchRun;
use App\Services\Analysis\AnalysisBacklog;
use App\Services\Enrichment\ContactRecorder;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs every enabled source, dedupes/stores the results and records a search_runs row.
 * A failing source is logged and skipped — it never fails the whole run.
 */
class DiscoveryService
{
    public const LOCK = 'outreach:discover:lock';

    /** @param iterable<CompanySourceInterface> $sources Tagged "outreach.sources" (see AppServiceProvider). */
    public function __construct(
        private readonly iterable $sources,
        private readonly CompanyUpserter $upserter,
        private readonly ContactRecorder $contacts,
        private readonly AnalysisBacklog $backlog,
        private readonly QueryRotation $rotation,
        private readonly Cache $cache,
    ) {}

    /** @return SearchRun|null null when another discovery run is already in progress */
    public function run(): ?SearchRun
    {
        $lock = $this->cache->lock(self::LOCK, 3 * 3600);
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->execute();
        } finally {
            $lock->release();
        }
    }

    private function execute(): SearchRun
    {
        $run = SearchRun::start(SearchRunType::Discover);
        $totals = ['new_companies' => 0, 'duplicates' => 0, 'contacts_from_sources' => 0, 'sources' => []];

        // Pool too small and queries used up → add fresh variations for under-searched cities first.
        $totals['queries_added'] = $this->rotation->expandIfNeeded((int) config('outreach.searxng.max_queries_per_run', 25));
        $newIds = [];

        foreach ($this->sources as $source) {
            if (! $source->isEnabled()) {
                continue;
            }

            $totals['sources'][] = $source->name();

            try {
                $candidates = $source->discover($run);
            } catch (Throwable $e) {
                $run->addError("{$source->name()}: {$e->getMessage()}");
                Log::error('Discovery source failed', ['source' => $source->name(), 'exception' => $e]);

                continue;
            }

            foreach ($candidates as $dto) {
                try {
                    [$company, $created] = $this->upserter->upsert($dto);
                    $totals['contacts_from_sources'] += $this->contacts->recordFromDiscovery($company, $dto);
                } catch (Throwable $e) {
                    $run->addError("store '{$dto->name}': {$e->getMessage()}");

                    continue;
                }

                if ($created) {
                    $totals['new_companies']++;
                    $newIds[] = $company->id;
                } else {
                    $totals['duplicates']++;
                }
            }
        }

        // New companies → crawl → AI. Plus: top up the AI backlog if the pool is still short.
        foreach ($newIds as $id) {
            EnrichCompanyJob::dispatch($id);
        }
        $totals['analysis_backlog_queued'] = $this->backlog->dispatchIfNeeded();
        $totals['pool_size'] = $this->backlog->poolSize();

        $run->finish($totals + ['new_company_ids' => $newIds]);

        return $run;
    }
}
