<?php

use App\Enums\QueryGroup;
use App\Enums\SettingKey;
use App\Models\BlockedDomain;
use App\Models\SearchQuery;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Hash;

test('seeders populate settings, ~40 queries across groups and cities, and blocked domains', function () {
    $this->seed();

    expect(Setting::count())->toBe(count(SettingKey::cases()))
        ->and(SearchQuery::count())->toBeGreaterThanOrEqual(40)
        ->and(SearchQuery::where('group', QueryGroup::Hiring)->count())->toBeGreaterThan(10)
        ->and(SearchQuery::where('group', QueryGroup::Discovery)->count())->toBeGreaterThan(20)
        ->and(SearchQuery::where('language', 'en')->count())->toBeGreaterThan(10)
        ->and(BlockedDomain::where('domain', 'bayt.com')->exists())->toBeTrue()
        ->and(BlockedDomain::where('domain', 'gov.sa')->exists())->toBeTrue();

    $cities = SearchQuery::whereNotNull('city')->distinct()->pluck('city')->all();
    expect($cities)->toEqualCanonicalizing(array_keys(config('outreach.cities')));
});

test('re-seeding is idempotent and keeps user edits', function () {
    $this->seed();
    app(SettingsRepository::class)->set(SettingKey::MyName, 'محمد رشاد');
    SearchQuery::first()->update(['is_active' => false]);

    $this->seed();

    expect(SearchQuery::count())->toBe(SearchQuery::distinct()->count('query'))
        ->and(app(SettingsRepository::class)->get(SettingKey::MyName))->toBe('محمد رشاد')
        ->and(SearchQuery::where('is_active', false)->count())->toBe(1);
});

test('settings repository falls back to defaults and casts values', function () {
    $settings = app(SettingsRepository::class);

    expect($settings->batchSize())->toBe(10)
        ->and($settings->batchTime())->toBe('09:00')
        ->and($settings->get(SettingKey::MessageTemplate))->toContain('{company_name}');

    $settings->setMany(['batch_size' => '12', 'batch_time' => 'bad', 'min_relevance' => 150]);

    expect($settings->batchSize())->toBe(12)
        ->and($settings->batchTime())->toBe('09:00')
        ->and($settings->minRelevance())->toBe(100);
});

test('create-user command creates the login user and can reset its password', function () {
    $this->artisan('outreach:create-user', ['--name' => 'Rashad', '--email' => 'me@example.com', '--password' => 'secret-123'])
        ->assertSuccessful();

    $user = User::where('email', 'me@example.com')->firstOrFail();
    expect(Hash::check('secret-123', $user->password))->toBeTrue();

    $this->artisan('outreach:create-user', ['--email' => 'me@example.com', '--password' => 'new-pass-456'])
        ->expectsOutputToContain('Password updated')
        ->assertSuccessful();

    expect(User::count())->toBe(1)
        ->and(Hash::check('new-pass-456', $user->refresh()->password))->toBeTrue();

    $this->post('/login', ['email' => 'me@example.com', 'password' => 'new-pass-456'])->assertRedirect('/');
    $this->assertAuthenticated();
});

test('create-user command rejects short passwords', function () {
    $this->artisan('outreach:create-user', ['--name' => 'x', '--email' => 'me@example.com', '--password' => 'short'])
        ->assertFailed();

    expect(User::count())->toBe(0);
});
