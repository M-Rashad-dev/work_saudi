<?php

namespace App\Models;

use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Enums\ContactType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property ?string $domain
 * @property ?string $website
 * @property ?string $city
 * @property CompanySource $source
 * @property CompanyStatus $status
 * @property int $relevance
 * @property bool $is_software_company
 * @property bool $is_hiring_devs
 */
class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'normalized_name', 'domain', 'website', 'city', 'linkedin_url', 'google_maps_url',
        'job_post_url', 'source', 'source_snippet', 'website_text', 'meta_description', 'enrichment',
        'description_ar', 'reason_ar', 'services', 'tech_hints', 'is_software_company', 'is_hiring_devs',
        'relevance', 'llm_driver', 'analysis_attempts', 'status', 'enriched_at', 'analyzed_at',
    ];

    /** Big text columns stay out of JSON/Inertia payloads by default. */
    protected $hidden = ['website_text', 'enrichment'];

    protected $attributes = [
        'status' => 'new',
        'source' => 'manual',
        'relevance' => 0,
        'is_software_company' => false,
        'is_hiring_devs' => false,
    ];

    protected function casts(): array
    {
        return [
            'source' => CompanySource::class,
            'status' => CompanyStatus::class,
            'services' => 'array',
            'tech_hints' => 'array',
            'enrichment' => 'array',
            'analysis_attempts' => 'integer',
            'is_software_company' => 'boolean',
            'is_hiring_devs' => 'boolean',
            'relevance' => 'integer',
            'enriched_at' => 'datetime',
            'analyzed_at' => 'datetime',
        ];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function phones(): HasMany
    {
        return $this->contacts()->where('type', ContactType::Phone);
    }

    public function emails(): HasMany
    {
        return $this->contacts()->where('type', ContactType::Email);
    }

    /** The single batch item (a company is shown at most once, ever). */
    public function batchItem(): HasOne
    {
        return $this->hasOne(DailyBatchItem::class);
    }

    public function scopeStatus(Builder $query, CompanyStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /** Qualified and never placed in any batch. */
    public function scopeAvailableForBatch(Builder $query): Builder
    {
        return $query->where('status', CompanyStatus::Qualified)->whereDoesntHave('batchItem');
    }

    /** Has a phone that can receive a WhatsApp message (wa.me link or mobile). */
    public function scopeHasWhatsapp(Builder $query): Builder
    {
        return $query->whereHas('contacts', fn (Builder $q) => $q
            ->where('type', ContactType::Phone)
            ->where(fn (Builder $q) => $q->where('is_whatsapp', true)->orWhere('is_mobile', true)));
    }

    public static function mapsUrlFor(string $name, ?string $city): string
    {
        $query = trim($name.' '.($city ?? ''));

        return 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }
}
