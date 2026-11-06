<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $domain
 * @property ?string $category directory | job_board | social | news | gov | reference | other
 */
class BlockedDomain extends Model
{
    public const CACHE_KEY = 'blocked_domains:map';

    public const JOB_BOARD = 'job_board';

    protected $fillable = ['domain', 'category'];

    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }

    /** @return array<string, string> domain => category ("other" when unset) */
    public static function cachedMap(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => self::query()->get(['domain', 'category'])
                ->mapWithKeys(fn (self $row) => [mb_strtolower($row->domain) => $row->category ?: 'other'])
                ->all(),
        );
    }
}
