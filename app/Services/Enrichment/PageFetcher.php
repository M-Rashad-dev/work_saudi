<?php

namespace App\Services\Enrichment;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Polite HTML fetcher: clear User-Agent, 10 s timeout, robots.txt respected, 1–3 s between requests
 * to the same host. Never throws — returns null and keeps the reason in $lastError.
 */
class PageFetcher
{
    private const MAX_BYTES = 3_000_000;

    public ?string $lastError = null;

    /** @var array<string, float> host => microtime of last request */
    private array $lastRequestAt = [];

    /** @var array<string, RobotsTxt> */
    private array $robots = [];

    /** @var array<string, true> */
    private array $insecureHosts = [];

    public function __construct(private readonly Http $http, private readonly Cache $cache) {}

    /** @return array{url: string, html: string}|null url = final URL after redirects */
    public function fetch(string $url): ?array
    {
        $this->lastError = null;

        if (! $this->robotsFor($url)->allows($url)) {
            $this->lastError = 'blocked by robots.txt';

            return null;
        }

        $response = $this->request($url);
        if ($response === null) {
            return null;
        }

        if (! $response->successful()) {
            $this->lastError = "HTTP {$response->status()}";

            return null;
        }

        $type = strtolower((string) $response->header('Content-Type'));
        if ($type !== '' && ! str_contains($type, 'html') && ! str_contains($type, 'xml')) {
            $this->lastError = "not HTML ({$type})";

            return null;
        }

        $html = substr((string) $response->body(), 0, self::MAX_BYTES);

        return [
            'url' => (string) ($response->effectiveUri() ?? $url),
            'html' => $this->toUtf8($html, $type),
        ];
    }

    public function robotsFor(string $url): RobotsTxt
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return RobotsTxt::disallowAll();
        }

        return $this->robots[$host] ??= $this->loadRobots("{$scheme}://{$host}/robots.txt");
    }

    private function loadRobots(string $robotsUrl): RobotsTxt
    {
        $content = $this->cache->remember('robots:'.sha1($robotsUrl), now()->addDay(), function () use ($robotsUrl) {
            $response = $this->request($robotsUrl);

            return match (true) {
                $response === null => '',                                  // unreachable: allow (homepage will fail anyway)
                $response->successful() => (string) $response->body(),
                $response->serverError() => "User-agent: *\nDisallow: /",  // RFC 9309: 5xx = assume full disallow
                default => '',                                             // 4xx = no rules
            };
        });

        return $content === '' ? RobotsTxt::allowAll() : RobotsTxt::parse($content, $this->botToken());
    }

    private function request(string $url): ?\Illuminate\Http\Client\Response
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $this->throttle($url);

        try {
            return $this->send($url, verify: ! isset($this->insecureHosts[$host]));
        } catch (Throwable $e) {
            // Many sites serve an incomplete certificate chain (browsers fix it via AIA, OpenSSL doesn't).
            // OPT-IN ONLY (CRAWLER_ALLOW_INSECURE_TLS=true): retry such a site once without verification.
            // Off by default — it is a security trade-off the owner decides on.
            if ((bool) config('outreach.crawler.allow_insecure_tls', false)
                && ! isset($this->insecureHosts[$host])
                && preg_match('/cURL error 60|SSL certificate problem/i', $e->getMessage())) {
                $this->insecureHosts[$host] = true;
                try {
                    return $this->send($url, verify: false);
                } catch (Throwable $retry) {
                    $e = $retry;
                }
            }
            $this->lastError = mb_substr($e->getMessage(), 0, 200);

            return null;
        } finally {
            $this->lastRequestAt[$host] = microtime(true);
        }
    }

    private function send(string $url, bool $verify): \Illuminate\Http\Client\Response
    {
        return $this->http
            ->withUserAgent((string) config('outreach.crawler.user_agent'))
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language' => 'ar,en;q=0.8'])
            ->timeout((int) config('outreach.crawler.timeout', 10))
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => ['max' => 5], 'verify' => $verify])
            ->get($url);
    }

    /** Hosts whose TLS chain failed verification once (see request()). */
    public function usedInsecureTls(string $url): bool
    {
        return isset($this->insecureHosts[strtolower((string) parse_url($url, PHP_URL_HOST))]);
    }

    private function throttle(string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! isset($this->lastRequestAt[$host])) {
            return;
        }

        $min = (int) config('outreach.crawler.delay_min_ms', 1000);
        $max = max($min, (int) config('outreach.crawler.delay_max_ms', 3000));
        $wait = random_int($min, $max) - (int) ((microtime(true) - $this->lastRequestAt[$host]) * 1000);

        if ($wait > 0) {
            Sleep::for($wait)->milliseconds();
        }
    }

    private function botToken(): string
    {
        return strtok((string) config('outreach.crawler.user_agent'), '/') ?: 'bot';
    }

    private function toUtf8(string $html, string $contentType): string
    {
        $charset = null;
        if (preg_match('/charset=["\']?([\w-]+)/i', $contentType, $m)
            || preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = strtoupper($m[1]);
        }

        if ($charset !== null && ! in_array($charset, ['UTF-8', 'UTF8'], true)) {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset === 'WINDOWS-1256' ? 'CP1256' : $charset);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return mb_check_encoding($html, 'UTF-8') ? $html : mb_convert_encoding($html, 'UTF-8', 'UTF-8');
    }
}
