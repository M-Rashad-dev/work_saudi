<?php

namespace App\Models;

use App\Enums\ContactLabel;
use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $company_id
 * @property ContactType $type
 * @property string $value
 * @property ContactLabel $label
 * @property bool $is_mobile
 * @property bool $is_whatsapp
 */
class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'type', 'value', 'label', 'is_mobile', 'is_whatsapp', 'source_url'];

    protected $attributes = [
        'label' => 'general',
        'is_mobile' => false,
        'is_whatsapp' => false,
    ];

    protected function casts(): array
    {
        return [
            'type' => ContactType::class,
            'label' => ContactLabel::class,
            'is_mobile' => 'boolean',
            'is_whatsapp' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function canWhatsapp(): bool
    {
        return $this->type === ContactType::Phone && ($this->is_whatsapp || $this->is_mobile);
    }

    /** Digits only, as wa.me expects (no "+"). */
    public function whatsappNumber(): ?string
    {
        return $this->canWhatsapp() ? ltrim($this->value, '+') : null;
    }
}
