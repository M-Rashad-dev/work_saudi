<?php

namespace App\Services\Discovery\Sources;

use App\Enums\CompanySource;
use App\Exceptions\SourceDisabledException;
use App\Services\Discovery\BlockedDomainMatcher;
use App\Services\Discovery\CityDetector;
use App\Services\Discovery\CompanyTitleParser;
use App\Services\Discovery\DomainNormalizer;
use App\Services\Discovery\JobBoardCompanyExtractor;
use App\Services\Discovery\NameNormalizer;
use App\Services\Discovery\SerperClient;
use App\Services\Discovery\SourceState;

/**
 * Optional Google results via Serper.dev. Only enabled when SERPER_API_KEY is set.
 * Runs after SearXNG, so the rotation hands it the *next* least-recently-run queries.
 * When credits run out it disables itself (persisted) and discovery continues with the other sources.
 */
class SerperSource extends SearchEngineSource
{
    public function __construct(
        private readonly SerperClient $client,
        private readonly SourceState $state,
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
        return filled(config('outreach.serper.api_key')) && ! $this->state->isDisabled($this->name());
    }

    protected function companySource(): CompanySource
    {
        return CompanySource::Serper;
    }

    protected function budget(): int
    {
        return (int) config('outreach.serper.max_queries_per_run', 5);
    }

    protected function delayRange(): array
    {
        return [1, 2];
    }

    protected function performSearch(string $query, string $language): array
    {
        try {
            return $this->client->search($query, $language);
        } catch (SourceDisabledException $e) {
            $this->state->disable($this->name(), $e->getMessage());

            throw $e;
        }
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
