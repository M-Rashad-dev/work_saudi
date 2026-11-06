<?php

namespace Database\Seeders;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /** Inserts defaults for missing keys only — never overwrites values edited in the UI. */
    public function run(SettingsRepository $settings): void
    {
        foreach (SettingKey::cases() as $key) {
            Setting::query()->firstOrCreate(['key' => $key->value], ['value' => $key->default()]);
        }

        $settings->flush();
    }
}
