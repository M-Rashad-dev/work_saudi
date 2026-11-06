<?php

namespace App\Services\Discovery;

use App\DTO\SearchResultDTO;
use Illuminate\Support\Str;

/**
 * Guesses a company's display name from a search result on the company's own site.
 * "الرئيسية - شركة أكمي للبرمجيات" → "شركة أكمي للبرمجيات"; "Acme | Web Development in Riyadh" → "Acme".
 * Falls back to the domain label ("acme-soft.com.sa" → "Acme Soft"). Phase 4 refines it from og:site_name.
 */
class CompanyTitleParser
{
    private const GENERIC_SEGMENTS = [
        'home', 'homepage', 'home page', 'welcome', 'index', 'main', 'about', 'about us', 'contact', 'contact us',
        'الرئيسية', 'الصفحة الرئيسية', 'الرئيسيه', 'مرحبا', 'من نحن', 'اتصل بنا', 'تواصل معنا',
    ];

    /** Titles of articles / listicles — the title is not the company name. */
    private const ARTICLE_HINTS = [
        'best', 'top ', 'list of', 'how to', 'guide', 'أفضل', 'افضل', 'أشهر', 'اشهر', 'قائمة', 'كيف', 'دليل',
        'تعرف على', 'ما هي', 'what is', 'vs', 'مقارنة', 'أسعار', 'اسعار', 'تكلفة',
    ];

    public function __construct(private readonly NameNormalizer $names) {}

    public function parse(SearchResultDTO $result, string $domain): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($result->title), ENT_QUOTES | ENT_HTML5)));

        if ($title === '' || $this->looksLikeArticle($title)) {
            return $this->fromDomain($domain);
        }

        $segments = array_values(array_filter(
            // Unicode-safe trim (PHP trim() is byte-based and would cut Arabic letters).
            array_map(fn ($s) => preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $s) ?? '', preg_split('/\s+[|\-–—:·•»]\s+|\s*[|•»]\s*/u', $title) ?: []),
            fn ($s) => $s !== '' && ! in_array(mb_strtolower($s), self::GENERIC_SEGMENTS, true),
        ));

        if ($segments === []) {
            return $this->fromDomain($domain);
        }

        // Prefer the segment that shares a token with the domain ("Acme Soft" ↔ acmesoft.com).
        $label = str_replace('-', '', $this->labelOf($domain));
        foreach ($segments as $segment) {
            foreach ($this->names->latinTokens($segment) as $token) {
                if (str_contains($label, $token) && mb_strlen($segment) <= 60) {
                    return $segment;
                }
            }
        }

        // Arabic names rarely match a Latin domain: use the segment that says "شركة/مؤسسة…", else the shortest.
        foreach ($segments as $segment) {
            if (preg_match('/^(شركة|مؤسسة|مجموعة)\s/u', $segment) && mb_strlen($segment) <= 60) {
                return $segment;
            }
        }

        $shortest = collect($segments)->sortBy(fn ($s) => mb_strlen($s))->first();

        return mb_strlen($shortest) <= 40 && $result->isHomepage() ? $shortest : $this->fromDomain($domain);
    }

    public function fromDomain(string $domain): string
    {
        return Str::of($this->labelOf($domain))->replace(['-', '_'], ' ')->title()->toString();
    }

    private function labelOf(string $domain): string
    {
        return explode('.', $domain)[0];
    }

    private function looksLikeArticle(string $title): bool
    {
        $lower = mb_strtolower($title);
        foreach (self::ARTICLE_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }

        // "10 شركات…", "Top 15…"
        return (bool) preg_match('/^\s*\d+\s|\s\d{1,2}\s+(شركات|companies)/u', $lower);
    }
}
