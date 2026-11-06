<?php

namespace App\Jobs;

use App\Services\Discovery\DiscoveryService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Queued discovery run — used by the dashboard button ("شغّل البحث الآن") and `outreach:discover --queue`.
 * A run takes several minutes (polite delays), so it lives on the long-running "discovery" queue.
 */
class RunDiscoveryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('discovery');
    }

    public function handle(DiscoveryService $discovery): void
    {
        $discovery->run();
    }
}
