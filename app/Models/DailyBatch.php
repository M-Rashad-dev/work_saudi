<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $date
 * @property ?Carbon $generated_at
 */
class DailyBatch extends Model
{
    protected $fillable = ['date', 'generated_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'generated_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DailyBatchItem::class)->orderBy('position');
    }
}
