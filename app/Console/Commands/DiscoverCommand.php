<?php

namespace App\Console\Commands;

use App\Jobs\RunDiscoveryJob;
use App\Services\Discovery\DiscoveryService;
use Illuminate\Console\Command;

class DiscoverCommand extends Command
{
    protected $signature = 'outreach:discover
                            {--limit= : Max SearXNG searches this run (default from config, 25)}
                            {--no-delay : Skip the polite 5–15 s pause between searches (testing only)}
                            {--queue : Dispatch to the queue instead of running now}';

    protected $description = 'Discover Saudi software companies / hiring signals and store them without duplicates';

    public function handle(DiscoveryService $discovery): int
    {
        if ($this->option('queue')) {
            RunDiscoveryJob::dispatch();
            $this->info('Discovery dispatched to the "discovery" queue.');

            return self::SUCCESS;
        }

        if ($this->option('limit') !== null) {
            config(['outreach.searxng.max_queries_per_run' => max(1, (int) $this->option('limit'))]);
        }
        if ($this->option('no-delay')) {
            config(['outreach.searxng.delay_min_seconds' => 0, 'outreach.searxng.delay_max_seconds' => 0]);
        }

        $this->info('Discovery started at '.now()->format('H:i:s').' — this can take a few minutes…');

        $run = $discovery->run();
        if ($run === null) {
            $this->warn('Another discovery run is already in progress. Skipped.');

            return self::SUCCESS;
        }

        $stats = $run->stats ?? [];
        $rows = [
            ['new companies', $stats['new_companies'] ?? 0],
            ['duplicates (already known)', $stats['duplicates'] ?? 0],
        ];
        foreach ($stats['searxng'] ?? [] as $key => $value) {
            $rows[] = ["searxng.{$key}", $value];
        }
        $this->table(['metric', 'value'], $rows);

        foreach ($run->errors ?? [] as $error) {
            $this->warn('! '.$error['message']);
        }

        $this->info("Run #{$run->id} finished in ".$run->started_at->diffForHumans($run->finished_at, true).'.');

        return self::SUCCESS;
    }
}
