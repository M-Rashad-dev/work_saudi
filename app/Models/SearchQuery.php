<?php

namespace App\Models;

use App\Enums\QueryGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $query
 * @property QueryGroup $group
 * @property string $language
 * @property ?string $city
 * @property bool $is_active
 * @property ?Carbon $last_run_at
 * @property int $results_count
 */
class SearchQuery extends Model
{
    protected $fillable = ['query', 'group', 'language', 'city', 'is_active', 'last_run_at', 'results_count'];

    protected $attributes = [
        'language' => 'ar',
        'is_active' => true,
        'results_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'group' => QueryGroup::class,
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'results_count' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Never-run queries first, then the least recently run — this is the rotation. */
    public function scopeRotationOrder(Builder $query): Builder
    {
        return $query->orderByRaw('last_run_at IS NOT NULL')->orderBy('last_run_at')->orderBy('id');
    }
}
