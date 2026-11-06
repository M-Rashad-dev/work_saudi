<?php

namespace Database\Factories;

use App\Enums\ContactLabel;
use App\Enums\ContactType;
use App\Models\Company;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => ContactType::Email,
            'value' => $this->faker->unique()->safeEmail(),
            'label' => ContactLabel::General,
        ];
    }

    public function mobile(): static
    {
        return $this->state(fn () => [
            'type' => ContactType::Phone,
            'value' => '+9665'.$this->faker->unique()->numerify('########'),
            'is_mobile' => true,
        ]);
    }

    public function whatsapp(): static
    {
        return $this->mobile()->state(fn () => ['is_whatsapp' => true, 'label' => ContactLabel::Whatsapp]);
    }

    public function landline(): static
    {
        return $this->state(fn () => [
            'type' => ContactType::Phone,
            'value' => '+96611'.$this->faker->unique()->numerify('#######'),
            'is_mobile' => false,
        ]);
    }
}
