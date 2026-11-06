<?php

namespace Database\Factories;

use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company().' Tech';
        $domain = Str::slug($name).'.sa';
        $city = $this->faker->randomElement(['الرياض', 'جدة', 'الدمام', 'الخبر']);

        return [
            'name' => $name,
            'normalized_name' => Str::lower(Str::slug($name, ' ')),
            'domain' => $domain,
            'website' => 'https://'.$domain,
            'city' => $city,
            'google_maps_url' => Company::mapsUrlFor($name, $city),
            'source' => CompanySource::Searxng,
            'status' => CompanyStatus::New,
            'relevance' => 0,
        ];
    }

    public function qualified(int $relevance = 80): static
    {
        return $this->state(fn () => [
            'status' => CompanyStatus::Qualified,
            'is_software_company' => true,
            'relevance' => $relevance,
            'description_ar' => 'شركة برمجيات تقدم حلول تطوير المواقع والتطبيقات.',
            'reason_ar' => 'شركة برمجيات',
            'analyzed_at' => now(),
            'enriched_at' => now(),
        ]);
    }

    public function hiring(): static
    {
        return $this->state(fn () => ['is_hiring_devs' => true]);
    }
}
