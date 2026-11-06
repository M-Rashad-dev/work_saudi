<?php

namespace App\Services\Discovery\Sources;

use App\Enums\CompanySource;
use App\Services\Discovery\BlockedDomainMatcher;
use App\Services\Discovery\CityDetector;
use App\Services\Discovery\CompanyTitleParser;
use App\Services\Discovery\DomainNormalizer;
use App\Services\Discovery\JobBoardCompanyExtractor;
use App\Services\Discovery\NameNormalizer;
use App\Services\Discovery\SearxngClient;
use Illuminate\Support\Facades\Log;

/**
 * Primary source: self-hosted SearXNG. Max ~25 searches/run with a random 5–15 s pause between them.
 */
class SearxngSource extends SearchEngineSource
{
    public function __construct(
        private readonly SearxngClient $client,
        BlockedDomainMatcher $blocked,
        JobBoardCompanyExtractor $jobBoards,
        CompanyTitleParser $titles,
        CityDetector $cities,
        DomainNormalizer $domains,
        NameNormalizer $names,
    ) {
        parent::__construct($blocked, $jobBoards, $titles, $cities, $domains, $names);
    }

    public function isEnabled(): bool
    {
        return filled(config('outreach.searxng.url'));
    }

    protected function companySource(): CompanySource
    {
        return CompanySource::Searxng;
    }

    protected function budget(): int
    {
        return (int) config('outreach.searxng.max_queries_per_run', 25);
    }

    protected function delayRange(): array
    {
        return [(int) config('outreach.searxng.delay_min_seconds', 5), (int) config('outreach.searxng.delay_max_seconds', 15)];
    }

    protected function performSearch(string $query, string $language): array
    {
        $results = $this->client->search($query, $language);

        if ($this->client->lastUnresponsiveEngines !== []) {
            Log::info('SearXNG unresponsive engines', ['query' => $query, 'engines' => $this->client->lastUnresponsiveEngines]);
        }

        return $results;
    }

    protected function isCached(string $query, string $language): bool
    {
        return $this->client->isCached($query, $language);
    }

    protected function lastFromCache(): bool
    {
        return $this->client->lastFromCache;
    }
}
