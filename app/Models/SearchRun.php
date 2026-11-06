<?php

namespace App\Models;

use App\Enums\SearchRunType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property SearchRunType $type
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?array $stats
 * @property ?array $errors
 */
class SearchRun extends Model
{
    protected $fillable = ['type', 'started_at', 'finished_at', 'stats', 'errors'];

    protected function casts(): array
    {
        return [
            'type' => SearchRunType::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'stats' => 'array',
            'errors' => 'array',
        ];
    }

    public static function start(SearchRunType $type): self
    {
        return self::create(['type' => $type, 'started_at' => now(), 'stats' => [], 'errors' => []]);
    }

    public function addError(string $message, array $context = []): void
    {
        $this->errors = [
            ...($this->errors ?? []),
            ['at' => now()->toIso8601String(), 'message' => $message, 'context' => $context],
        ];
    }

    public function finish(array $stats = []): void
    {
        $this->stats = [...($this->stats ?? []), ...$stats];
        $this->finished_at = now();
        $this->save();
    }

    public function hasErrors(): bool
    {
        return ! empty($this->errors);
    }
}
