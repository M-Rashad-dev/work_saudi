<?php

namespace App\Services\Discovery;

use App\DTO\SearchResultDTO;
use App\Exceptions\SearchUnavailableException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Thin client for a self-hosted SearXNG JSON API.
 * Successful responses are cached for 24 h, so re-running a query the same day costs nothing.
 */
class SearxngClient
{
    /** Set after each search(): engines SearXNG reported as failing (for run logs). */
    public array $lastUnresponsiveEngines = [];

    /** True when the last search() was served from cache (no delay needed before the next one). */
    public bool $lastFromCache = false;

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly DomainNormalizer $domains,
    ) {}

    /**
     * @param  string  $language  "ar" | "en" (mapped to SearXNG locales)
     * @return list<SearchResultDTO>
     *
     * @throws SearchUnavailableException
     */
    public function search(string $query, string $language = 'ar'): array
    {
        $locale = $this->locale($language);
        $key = $this->cacheKey($query, $locale);

        $this->lastUnresponsiveEngines = [];
        $this->lastFromCache = $this->cache->has($key);

        if ($this->lastFromCache) {
            return $this->toResults((array) $this->cache->get($key));
        }

        $payload = $this->fetch($query, $locale);
        $raw = $payload['results'] ?? [];
        $this->lastUnresponsiveEngines = array_map(
            fn ($e) => is_array($e) ? implode(': ', $e) : (string) $e,
            $payload['unresponsive_engines'] ?? [],
        );

        // Don't cache empty answers: they are usually engines being rate-limited, not "no results".
        if ($raw !== []) {
            $this->cache->put($key, $raw, now()->addHours((int) config('outreach.searxng.cache_ttl_hours', 24)));
        }

        return $this->toResults($raw);
    }

    public function isCached(string $query, string $language = 'ar'): bool
    {
        return $this->cache->has($this->cacheKey($query, $this->locale($language)));
    }

    private function locale(string $language): string
    {
        return $language === 'ar' ? 'ar-SA' : 'en';
    }

    private function cacheKey(string $query, string $locale): string
    {
        return 'searxng:'.sha1($query.'|'.$locale);
    }

    private function fetch(string $query, string $locale): array
    {
        try {
            $response = $this->http
                ->baseUrl(config('outreach.searxng.url'))
                ->acceptJson()
                ->timeout((int) config('outreach.searxng.timeout', 20))
                ->retry(
                    [1000, 3000, 9000], // exponential backoff
                    when: fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                    throw: false,
                )
                ->get('/search', [
                    'q' => $query,
                    'format' => 'json',
                    'language' => $locale,
                    'safesearch' => 0,
                ]);
        } catch (Throwable $e) {
            throw new SearchUnavailableException('SearXNG unreachable: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            $hint = $response->status() === 403 ? ' (is "json" enabled in search.formats?)' : '';
            throw new SearchUnavailableException("SearXNG HTTP {$response->status()}{$hint}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new SearchUnavailableException('SearXNG returned non-JSON output');
        }

        return $json;
    }

    /** @return list<SearchResultDTO> */
    private function toResults(array $raw): array
    {
        $results = [];
        $seen = [];

        foreach ($raw as $item) {
            $url = (string) ($item['url'] ?? '');
            if ($url === '' || isset($seen[$url]) || ! preg_match('#^https?://#i', $url)) {
                continue;
            }
            $seen[$url] = true;

            $results[] = new SearchResultDTO(
                url: $url,
                title: trim((string) ($item['title'] ?? '')),
                snippet: trim((string) ($item['content'] ?? '')),
                domain: $this->domains->fromUrl($url),
                engine: isset($item['engine']) ? (string) $item['engine'] : null,
            );
        }

        return $results;
    }
}
