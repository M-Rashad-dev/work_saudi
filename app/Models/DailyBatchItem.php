<?php

namespace App\Models;

use App\Enums\BatchAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $daily_batch_id
 * @property int $company_id
 * @property int $position
 * @property BatchAction $action
 * @property ?Carbon $action_at
 * @property ?string $note
 */
class DailyBatchItem extends Model
{
    protected $fillable = ['daily_batch_id', 'company_id', 'position', 'action', 'action_at', 'note'];

    protected $attributes = ['action' => 'pending'];

    protected function casts(): array
    {
        return [
            'action' => BatchAction::class,
            'action_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DailyBatch::class, 'daily_batch_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
