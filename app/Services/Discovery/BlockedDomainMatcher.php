<?php

namespace App\Services\Discovery;

use App\Models\BlockedDomain;

/**
 * Suffix matching against the blocked_domains table:
 * "gov.sa" blocks "moe.gov.sa"; "google.com" blocks "play.google.com"; "x.com" does NOT block "box.com".
 */
class BlockedDomainMatcher
{
    /** @var array<string, string>|null */
    private ?array $map = null;

    /** @param array<string, string>|null $map domain => category; null = load from DB (cached) */
    public function __construct(?array $map = null)
    {
        $this->map = $map;
    }

    /** Category of the blocking rule ("job_board", "social"…), or null if allowed. */
    public function category(?string $host): ?string
    {
        if ($host === null || $host === '') {
            return null;
        }

        $map = $this->map ??= BlockedDomain::cachedMap();
        $labels = explode('.', mb_strtolower(trim($host, '.')));

        // Check "a.b.c.sa", "b.c.sa", "c.sa", "sa" — label boundaries only.
        for ($i = 0, $n = count($labels); $i < $n; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (isset($map[$candidate])) {
                return $map[$candidate];
            }
        }

        return null;
    }

    public function isBlocked(?string $host): bool
    {
        return $this->category($host) !== null;
    }

    public function isJobBoard(?string $host): bool
    {
        return $this->category($host) === BlockedDomain::JOB_BOARD;
    }

    /** Call after the blocked list changes in the same process. */
    public function refresh(): void
    {
        $this->map = null;
    }
}
