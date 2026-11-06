<?php

namespace App\Services\Discovery;

/**
 * Reduces any URL/host to its registrable domain: "https://WWW.jobs.Acme.com.sa:443/x" → "acme.com.sa".
 * This is the primary dedupe key for companies.
 */
class DomainNormalizer
{
    /**
     * Second-level public suffixes we actually meet (Saudi + GCC + common). A full PSL is overkill here.
     */
    private const MULTI_PART_SUFFIXES = [
        'com.sa', 'net.sa', 'org.sa', 'edu.sa', 'gov.sa', 'med.sa', 'pub.sa', 'sch.sa',
        'co.ae', 'net.ae', 'org.ae', 'gov.ae', 'ac.ae',
        'com.eg', 'com.bh', 'com.kw', 'com.qa', 'com.om', 'co.om', 'com.jo', 'com.lb', 'com.tr', 'com.pk',
        'co.uk', 'org.uk', 'ac.uk', 'com.au', 'co.in', 'co.za', 'co.jp', 'com.cn', 'com.sg', 'com.my', 'com.br',
    ];

    public function fromUrl(?string $url): ?string
    {
        $host = $this->host($url);

        return $host === null ? null : $this->registrable($host);
    }

    /** Lowercased host without "www." and port, or null if the URL has no usable host. */
    public function host(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'http://'.ltrim($url, '/');
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = trim(mb_strtolower($host), '.');
        $host = preg_replace('/^www\d*\./', '', $host);

        // IPs and single-label hosts (localhost) are never company websites.
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return null;
        }

        return $host;
    }

    public function registrable(string $host): string
    {
        $labels = explode('.', trim(mb_strtolower($host), '.'));
        $count = count($labels);
        if ($count <= 2) {
            return implode('.', $labels);
        }

        $lastTwo = $labels[$count - 2].'.'.$labels[$count - 1];
        $keep = in_array($lastTwo, self::MULTI_PART_SUFFIXES, true) ? 3 : 2;

        return implode('.', array_slice($labels, -$keep));
    }

    /**
     * Homepage URL for a result URL. Subdomains like jobs./careers. collapse to the main domain;
     * "www." is kept when the result used it (some servers only answer on www).
     */
    public function homepage(?string $url): ?string
    {
        $domain = $this->fromUrl($url);
        if ($domain === null) {
            return null;
        }

        $scheme = parse_url((string) $url, PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
        $rawHost = mb_strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        $host = $rawHost === 'www.'.$domain ? $rawHost : $domain;

        return "{$scheme}://{$host}";
    }

    /** The name-like part of a domain: "acme-soft.com.sa" → "acme-soft". */
    public function label(string $domain): string
    {
        return explode('.', $domain)[0];
    }
}
