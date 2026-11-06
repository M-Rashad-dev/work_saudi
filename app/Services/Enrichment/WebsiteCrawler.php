<?php

namespace App\Services\Enrichment;

use App\Contracts\JsRendererInterface;
use App\DTO\ContactCandidate;
use App\DTO\EnrichmentResult;
use App\Services\Discovery\DomainNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawls a company site: homepage + up to 5 likely pages (contact, careers, about…), max 6 requests.
 * Pages are chosen from links found on the homepage first, then common paths as a fallback.
 * Short (JS-rendered) pages are re-fetched through the headless browser.
 */
class WebsiteCrawler
{
    /** Link text / URL keywords per page kind, best kind first. */
    private const KINDS = [
        'contact' => ['تواصل', 'اتصل', 'اتصال', 'contact', 'reach us', 'get in touch'],
        'careers' => ['وظائف', 'توظيف', 'انضم', 'careers', 'career', 'jobs', 'join', 'hiring', 'work with us', 'vacanc'],
        'about' => ['من نحن', 'عن الشركة', 'عنا', 'about', 'who we are', 'company'],
    ];

    /** Fallback paths when the homepage has no matching link. */
    private const GUESSES = [
        'contact' => ['/contact-us', '/contact', '/contactus', '/ar/contact', '/ar/contact-us', '/اتصل-بنا', '/تواصل-معنا'],
        'careers' => ['/careers', '/jobs', '/ar/careers', '/وظائف'],
        'about' => ['/about', '/about-us', '/ar/about', '/من-نحن'],
    ];

    private const SKIP_EXTENSIONS = '/\.(pdf|jpe?g|png|gif|webp|svg|zip|rar|docx?|xlsx?|pptx?|mp4|mp3)(\?|$)/i';

    private const TEXT_PER_PAGE = 4000;

    private const TEXT_TOTAL = 12000;

    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly JsRendererInterface $renderer,
        private readonly PhoneExtractor $phones,
        private readonly EmailExtractor $emails,
        private readonly DomainNormalizer $domains,
    ) {}

    public function crawl(string $website): EnrichmentResult
    {
        $result = new EnrichmentResult;
        $maxPages = (int) config('outreach.crawler.max_pages_per_site', 6);
        $requests = 0;
        $renders = 0;

        // Homepage (https first, then http if the TLS/connection fails).
        $home = null;
        foreach (array_unique([$website, preg_replace('#^https://#i', 'http://', $website)]) as $candidate) {
            if ($requests >= $maxPages) {
                break;
            }
            $requests++;
            $home = $this->fetcher->fetch($candidate);
            $result->pages[] = ['url' => $candidate, 'status' => $home ? $this->okStatus($candidate) : (string) $this->fetcher->lastError];
            if ($home !== null) {
                break;
            }
        }

        if ($home === null) {
            $result->errors[] = 'homepage unreachable: '.$this->fetcher->lastError;

            return $result;
        }

        $result->reachable = true;
        $siteDomain = $this->domains->fromUrl($home['url']) ?? $this->domains->fromUrl($website);
        $homeDoc = $this->maybeRender(new HtmlDocument($home['html'], $home['url']), $renders, $result);

        $result->metaDescription = $homeDoc->metaDescription();
        $result->siteName = $homeDoc->siteName();

        /** @var list<array{doc: HtmlDocument, kind: string}> $docs */
        $docs = [['doc' => $homeDoc, 'kind' => 'home']];
        $visited = [$this->key($home['url']) => true, $this->key($website) => true];

        foreach ($this->plan($homeDoc, $siteDomain) as [$url, $kind]) {
            if ($requests >= $maxPages) {
                break;
            }
            if (isset($visited[$this->key($url)])) {
                continue;
            }
            $visited[$this->key($url)] = true;

            if (! $this->fetcher->robotsFor($url)->allows($url)) {
                $result->pages[] = ['url' => $url, 'status' => 'blocked by robots.txt'];

                continue; // not a request — doesn't use the page budget
            }

            $requests++;
            $page = $this->fetcher->fetch($url);
            $result->pages[] = ['url' => $url, 'status' => $page ? $this->okStatus($url) : (string) $this->fetcher->lastError];
            if ($page === null) {
                continue;
            }

            $visited[$this->key($page['url'])] = true;
            $docs[] = ['doc' => $this->maybeRender(new HtmlDocument($page['html'], $page['url']), $renders, $result), 'kind' => $kind];
        }

        $this->extract($docs, $result);

        return $result;
    }

    /**
     * Ordered [url, kind] list: homepage links per kind (best-scored first), then guessed paths
     * for kinds that had no link.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function plan(HtmlDocument $home, ?string $siteDomain): array
    {
        $byKind = array_fill_keys(array_keys(self::KINDS), []);

        foreach ($home->links() as $link) {
            $url = strtok($link['url'], '#');
            if (! preg_match('#^https?://#i', $url) || preg_match(self::SKIP_EXTENSIONS, $url)) {
                continue;
            }
            if ($siteDomain !== null && $this->domains->fromUrl($url) !== $siteDomain) {
                continue; // external
            }

            $haystack = mb_strtolower($link['text'].' '.rawurldecode((string) parse_url($url, PHP_URL_PATH)));
            foreach (self::KINDS as $kind => $words) {
                foreach ($words as $word) {
                    if (str_contains($haystack, $word)) {
                        $byKind[$kind][$url] = true;

                        continue 3; // one kind per link
                    }
                }
            }
        }

        $base = rtrim(preg_replace('#^(https?://[^/]+).*$#i', '$1', $home->url), '/');
        $plan = [];
        // Interleave: first link of each kind, then second of each kind…
        for ($round = 0; $round < 2; $round++) {
            foreach ($byKind as $kind => $urls) {
                if (($url = array_keys($urls)[$round] ?? null) !== null) {
                    $plan[] = [$url, $kind];
                }
            }
        }
        foreach ($byKind as $kind => $urls) {
            if ($urls === []) {
                foreach (array_slice(self::GUESSES[$kind], 0, 2) as $path) {
                    $plan[] = [$base.$path, $kind];
                }
            }
        }

        return $plan;
    }

    private function maybeRender(HtmlDocument $doc, int &$renders, EnrichmentResult $result): HtmlDocument
    {
        $minText = (int) config('outreach.crawler.min_visible_text', 300);
        if (mb_strlen($doc->visibleText()) >= $minText
            || $renders >= (int) config('outreach.browsershot.max_renders_per_site', 2)
            || ! $this->renderer->isAvailable()) {
            return $doc;
        }

        $renders++;
        try {
            $rendered = new HtmlDocument($this->renderer->render($doc->url), $doc->url);
            $result->pages[] = ['url' => $doc->url, 'status' => 'rendered', 'rendered' => true];

            return mb_strlen($rendered->visibleText()) > mb_strlen($doc->visibleText()) ? $rendered : $doc;
        } catch (Throwable $e) {
            $result->errors[] = 'render failed: '.mb_substr($e->getMessage(), 0, 150);
            Log::info('Browsershot render failed', ['url' => $doc->url, 'error' => $e->getMessage()]);

            return $doc;
        }
    }

    /** @param list<array{doc: HtmlDocument, kind: string}> $docs */
    private function extract(array $docs, EnrichmentResult $result): void
    {
        /** @var array<string, ContactCandidate> $phones */
        $phones = [];
        $emails = [];
        $texts = [];

        foreach ($docs as ['doc' => $doc, 'kind' => $kind]) {
            $isCareers = $kind === 'careers';

            foreach ($this->phones->extract($doc, $isCareers) as $phone) {
                if (! isset($phones[$phone->value]) || $phone->rank() > $phones[$phone->value]->rank()) {
                    $phones[$phone->value] = $phone;
                }
            }
            foreach ($this->emails->extract($doc, $isCareers) as $email) {
                $emails[$email->value] ??= $email;
            }

            $result->linkedinUrl ??= $this->linkedin($doc);

            // Home + about describe the company best; contact pages add little text for the AI.
            if ($kind !== 'contact') {
                $texts[] = mb_substr($doc->visibleText(), 0, self::TEXT_PER_PAGE);
            }
        }

        $result->phones = collect($phones)->sortByDesc(fn (ContactCandidate $c) => $c->rank())->values()->all();
        $result->emails = $this->emails->sort(array_values($emails));
        $result->text = mb_substr(implode("\n\n", array_unique(array_filter($texts))), 0, self::TEXT_TOTAL);
    }

    private function linkedin(HtmlDocument $doc): ?string
    {
        foreach ($doc->links() as $link) {
            if (preg_match('#^https?://(?:[a-z]{2,3}\.)?(?:www\.)?linkedin\.com/company/([^/?\#\s]+)#i', $link['url'], $m)) {
                return 'https://www.linkedin.com/company/'.$m[1];
            }
        }

        return null;
    }

    private function okStatus(string $url): string
    {
        return $this->fetcher->usedInsecureTls($url) ? 'ok (TLS not verified)' : 'ok';
    }

    private function key(string $url): string
    {
        $parts = parse_url(strtok($url, '#'));

        return strtolower(preg_replace('/^www\./', '', $parts['host'] ?? '')).rtrim(rawurldecode($parts['path'] ?? '/'), '/')
            .(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
