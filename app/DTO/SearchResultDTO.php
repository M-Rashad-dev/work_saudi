<?php

namespace App\DTO;

/**
 * One organic search result (from SearXNG or Serper).
 */
final readonly class SearchResultDTO
{
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
        public ?string $domain,
        public ?string $engine = null,
    ) {}

    /** True when the URL points at the site root ("/", "/ar", "/en", "/index.html"…). */
    public function isHomepage(): bool
    {
        $path = trim((string) parse_url($this->url, PHP_URL_PATH), '/');

        return $path === '' || preg_match('#^(ar|en|ar-sa|en-us|home|index(\.\w+)?)$#i', $path) === 1;
    }

    public function text(): string
    {
        return trim($this->title.' '.$this->snippet);
    }
}
