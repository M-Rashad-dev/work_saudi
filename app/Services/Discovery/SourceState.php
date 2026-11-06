<?php

namespace App\Services\Discovery;

use App\Models\Setting;

/**
 * Persists "auto-disabled" flags for sources (e.g. Serper out of credits) in the settings table,
 * so they survive cache clears and deploys. Re-enable from the UI (Phase 7) or `outreach:source-enable`.
 */
class SourceState
{
    private const PREFIX = 'source_disabled:';

    public function disable(string $source, string $reason): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::PREFIX.$source],
            ['value' => ['reason' => mb_substr($reason, 0, 500), 'at' => now()->toIso8601String()]],
        );
    }

    public function enable(string $source): void
    {
        Setting::query()->whereKey(self::PREFIX.$source)->delete();
    }

    public function isDisabled(string $source): bool
    {
        return Setting::query()->whereKey(self::PREFIX.$source)->exists();
    }

    /** @return array{reason: string, at: string}|null */
    public function info(string $source): ?array
    {
        return Setting::query()->find(self::PREFIX.$source)?->value;
    }
}
