<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Safe to run repeatedly. The login user is created separately:
     *   php artisan outreach:create-user
     */
    public function run(): void
    {
        $this->call([
            SettingsSeeder::class,
            SearchQuerySeeder::class,
            BlockedDomainSeeder::class,
        ]);
    }
}
