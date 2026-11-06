<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value row. Read and write through App\Services\Settings\SettingsRepository, not directly.
 *
 * @property string $key
 * @property mixed $value
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
