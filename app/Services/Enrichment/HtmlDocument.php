<?php

namespace App\Services\Enrichment;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Read-only view of one HTML page: visible text, meta tags and links (resolved to absolute URLs).
 */
class HtmlDocument
{
    private ?Crawler $crawler = null;

    private ?string $text = null;

    /** @var list<array{url: string, text: string}>|null */
    private ?array $links = null;

    public function __construct(public readonly string $html, public readonly string $url) {}

    /** Visible text; block elements become line breaks so paragraphs survive. */
    public function visibleText(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        $html = preg_replace('#<(script|style|noscript|svg|template|iframe|head)\b[^>]*>.*?</\1>#is', ' ', $this->html) ?? '';
        $html = preg_replace('#<!--.*?-->#s', ' ', $html) ?? '';
        $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/section|/article|/header|/footer|/ul|/ol|/table|/address)\b[^>]*>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}\x{200B}-\x{200F}]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? '';

        return $this->text = trim($text);
    }

    public function title(): ?string
    {
        return $this->first('title', fn (Crawler $n) => $n->text(''));
    }

    public function metaDescription(): ?string
    {
        return $this->meta('description') ?? $this->meta('og:description', 'property');
    }

    public function siteName(): ?string
    {
        return $this->meta('og:site_name', 'property') ?? $this->meta('application-name');
    }

    /** @return list<array{url: string, text: string}> absolute http(s) links + mailto/tel/whatsapp links */
    public function links(): array
    {
        if ($this->links !== null) {
            return $this->links;
        }

        $links = [];
        try {
            foreach ($this->crawler()->filter('a[href]') as $node) {
                $href = trim((string) $node->getAttribute('href'));
                if ($href === '' || str_starts_with($href, '#') || str_starts_with(strtolower($href), 'javascript:')) {
                    continue;
                }
                $links[] = [
                    'url' => $this->absolute($href),
                    'text' => trim(preg_replace('/\s+/u', ' ', $node->textContent.' '.$node->getAttribute('title').' '.$node->getAttribute('aria-label'))),
                ];
            }
        } catch (Throwable) {
            // Malformed HTML: fall back to regex.
            preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $this->html, $m);
            foreach ($m[1] as $href) {
                $links[] = ['url' => $this->absolute(html_entity_decode($href)), 'text' => ''];
            }
        }

        return $this->links = $links;
    }

    /** Cloudflare "email protection" encoded addresses (data-cfemail="…"). */
    public function cloudflareEmails(): array
    {
        preg_match_all('/data-cfemail=["\']([0-9a-f]+)["\']|\/cdn-cgi\/l\/email-protection#([0-9a-f]+)/i', $this->html, $m);

        return array_values(array_filter(array_map(
            fn (string $hex) => $hex === '' ? null : self::decodeCfEmail($hex),
            [...$m[1], ...$m[2]],
        )));
    }

    public static function decodeCfEmail(string $hex): ?string
    {
        if (strlen($hex) < 4 || strlen($hex) % 2 !== 0) {
            return null;
        }
        $key = hexdec(substr($hex, 0, 2));
        $email = '';
        for ($i = 2; $i < strlen($hex); $i += 2) {
            $email .= chr(hexdec(substr($hex, $i, 2)) ^ $key);
        }

        return str_contains($email, '@') ? $email : null;
    }

    private function absolute(string $href): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) {
            return $href; // already absolute (http:, mailto:, tel:, whatsapp:)
        }

        $base = parse_url($this->url);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        if (str_starts_with($href, '//')) {
            return "{$scheme}:{$href}";
        }
        if (str_starts_with($href, '/')) {
            return "{$scheme}://{$host}{$href}";
        }

        $dir = preg_replace('#/[^/]*$#', '/', $base['path'] ?? '/');

        return "{$scheme}://{$host}".($dir ?: '/').$href;
    }

    private function meta(string $name, string $attr = 'name'): ?string
    {
        return $this->first("meta[{$attr}=\"{$name}\"]", fn (Crawler $n) => $n->attr('content'));
    }

    private function first(string $selector, callable $value): ?string
    {
        try {
            $node = $this->crawler()->filter($selector);
            $result = $node->count() ? trim((string) $value($node->first())) : '';
        } catch (Throwable) {
            return null;
        }

        return $result !== '' ? html_entity_decode($result, ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    private function crawler(): Crawler
    {
        if ($this->crawler === null) {
            $this->crawler = new Crawler(null, $this->url);
            $this->crawler->addHtmlContent($this->html, 'UTF-8');
        }

        return $this->crawler;
    }
}
