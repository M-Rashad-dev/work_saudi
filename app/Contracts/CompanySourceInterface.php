<?php

namespace App\Contracts;

use App\DTO\DiscoveredCompanyDTO;
use App\Models\SearchRun;
use Illuminate\Support\Collection;

/**
 * A place companies are discovered from (SearXNG, Overpass, Serper…).
 * Implementations must never throw for a partial failure: log to the run and return what was found.
 */
interface CompanySourceInterface
{
    /** Short identifier used in logs and run stats, e.g. "searxng". */
    public function name(): string;

    /** False when not configured (missing key/URL) or auto-disabled. */
    public function isEnabled(): bool;

    /**
     * @return Collection<int, DiscoveredCompanyDTO>
     */
    public function discover(SearchRun $run): Collection;
}
