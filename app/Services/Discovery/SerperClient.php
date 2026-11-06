<?php

namespace App\Services\Discovery;

use App\DTO\SearchResultDTO;
use App\Exceptions\SearchUnavailableException;
use App\Exceptions\SourceDisabledException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Serper.dev Google Search API — optional, free signup credits only.
 * Credit/quota/auth errors throw SourceDisabledException (permanent); other errors are transient.
 */
class SerperClient
{
    public bool $lastFromCache = false;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly DomainNormalizer $domains,
    ) {}

    /** @return list<SearchResultDTO> */
    public function search(string $query, string $language = 'ar'): array
    {
        $key = $this->cacheKey($query, $language);
        $this->lastFromCache = $this->cache->has($key);
        if ($this->lastFromCache) {
            return $this->toResults((array) $this->cache->get($key));
        }

        try {
            $response = $this->http
                ->withHeaders(['X-API-KEY' => (string) config('outreach.serper.api_key')])
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->retry(
                    [1000, 3000],
                    when: fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && $e->response->serverError()),
                    throw: false,
                )
                ->post(config('outreach.serper.url'), [
                    'q' => $query,
                    'gl' => 'sa',
                    'hl' => $language === 'ar' ? 'ar' : 'en',
                    'num' => 10,
                ]);
        } catch (Throwable $e) {
            throw new SearchUnavailableException('Serper unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($this->isQuotaError($response->status(), (string) $response->body())) {
            throw new SourceDisabledException("Serper credits/quota exhausted or key rejected (HTTP {$response->status()}): "
                .mb_substr((string) ($response->json('message') ?? $response->body()), 0, 200));
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new SearchUnavailableException("Serper HTTP {$response->status()}");
        }

        $organic = (array) $response->json('organic', []);
        if ($organic !== []) {
            $this->cache->put($key, $organic, now()->addHours((int) config('outreach.searxng.cache_ttl_hours', 24)));
        }

        return $this->toResults($organic);
    }

    public function isCached(string $query, string $language): bool
    {
        return $this->cache->has($this->cacheKey($query, $language));
    }

    private function isQuotaError(int $status, string $body): bool
    {
        if (in_array($status, [401, 402, 403], true)) {
            return true;
        }

        return ($status === 400 || $status === 429)
            && preg_match('/credit|quota|limit exceeded|not enough|unauthori[sz]ed|invalid api key/i', $body) === 1;
    }

    private function cacheKey(string $query, string $language): string
    {
        return 'serper:'.sha1($query.'|'.$language);
    }

    /** @return list<SearchResultDTO> */
    private function toResults(array $organic): array
    {
        $results = [];
        foreach ($organic as $item) {
            $url = (string) ($item['link'] ?? '');
            if (! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $results[] = new SearchResultDTO(
                url: $url,
                title: trim((string) ($item['title'] ?? '')),
                snippet: trim((string) ($item['snippet'] ?? '')),
                domain: $this->domains->fromUrl($url),
                engine: 'serper',
            );
        }

        return $results;
    }
}
