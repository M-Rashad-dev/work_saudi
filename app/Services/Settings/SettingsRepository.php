<?php

namespace App\Services\Settings;

use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Typed, cached access to the `settings` table. Registered as a singleton.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'settings:all';

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function __construct(private readonly Cache $cache) {}

    public function get(SettingKey $key): mixed
    {
        $all = $this->all();

        return array_key_exists($key->value, $all) && $all[$key->value] !== null
            ? $all[$key->value]
            : $key->default();
    }

    public function set(SettingKey $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key->value], ['value' => $value]);
        $this->flush();
    }

    /** @param array<string, mixed> $values keyed by SettingKey value */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => SettingKey::from($key)->value], ['value' => $value]);
        }
        $this->flush();
    }

    /** @return array<string, mixed> Every setting with defaults applied. */
    public function toArray(): array
    {
        $out = [];
        foreach (SettingKey::cases() as $key) {
            $out[$key->value] = $this->get($key);
        }

        return $out;
    }

    // ---- Typed helpers -------------------------------------------------

    public function batchSize(): int
    {
        return max(1, (int) $this->get(SettingKey::BatchSize));
    }

    /** "HH:MM" */
    public function batchTime(): string
    {
        $time = (string) $this->get(SettingKey::BatchTime);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) ? $time : (string) SettingKey::BatchTime->default();
    }

    public function minRelevance(): int
    {
        return min(100, max(0, (int) $this->get(SettingKey::MinRelevance)));
    }

    /** @return list<string> */
    public function llmDrivers(): array
    {
        $drivers = $this->get(SettingKey::LlmDrivers);

        return array_values(array_filter(is_array($drivers) ? $drivers : explode(',', (string) $drivers)));
    }

    public function flush(): void
    {
        $this->loaded = null;
        $this->cache->forget(self::CACHE_KEY);
    }

    /** @return array<string, mixed> */
    private function all(): array
    {
        return $this->loaded ??= $this->cache->rememberForever(
            self::CACHE_KEY,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );
    }
}
