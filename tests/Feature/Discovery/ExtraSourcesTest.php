<?php

use App\Enums\CompanySource;
use App\Enums\QueryGroup;
use App\Models\Company;
use App\Models\Contact;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use App\Services\Discovery\SourceState;
use App\Services\Discovery\Sources\OverpassSource;
use App\Services\Discovery\Sources\SerperSource;
use Database\Seeders\BlockedDomainSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Queue::fake();
    $this->seed(BlockedDomainSeeder::class);
    config(['outreach.searxng.url' => null]); // isolate the source under test
});

describe('Overpass', function () {
    beforeEach(fn () => config(['outreach.overpass.url' => 'https://overpass.test/api/interpreter']));

    function overpassElements(): array
    {
        return ['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 24.71, 'lon' => 46.67, 'tags' => [
                'office' => 'it', 'name' => 'Acme Soft', 'name:ar' => 'أكمي سوفت', 'phone' => '+966 11 234 5678;0551234567',
                'website' => 'acmesoft.sa', 'addr:city' => 'Riyadh', 'email' => 'info@acmesoft.sa',
            ]],
            ['type' => 'way', 'id' => 2, 'center' => ['lat' => 21.49, 'lon' => 39.19], 'tags' => [
                'office' => 'company', 'name' => 'Jeddah Digital', 'contact:whatsapp' => '+966 50 765 4321',
            ]],
            ['type' => 'node', 'id' => 3, 'lat' => 26.42, 'lon' => 50.09, 'tags' => [
                'office' => 'it', 'name' => 'FB Only Tech', 'website' => 'https://facebook.com/fbonly', 'phone' => '0138001234',
            ]],
            ['type' => 'node', 'id' => 4, 'lat' => 24.7, 'lon' => 46.6, 'tags' => ['office' => 'it', 'name' => 'No Contact IT']],
        ]];
    }

    test('stores OSM offices with phones, WhatsApp, emails and city; one live query per day', function () {
        Http::fake(['overpass.test/*' => Http::response(overpassElements())]);

        $this->artisan('outreach:discover')->assertSuccessful();

        expect(Company::count())->toBe(3); // "No Contact IT" skipped

        $acme = Company::where('domain', 'acmesoft.sa')->firstOrFail();
        expect($acme->name)->toBe('أكمي سوفت')
            ->and($acme->source)->toBe(CompanySource::Overpass)
            ->and($acme->city)->toBe('الرياض')
            ->and($acme->website)->toBe('https://acmesoft.sa')
            ->and(Contact::where('company_id', $acme->id)->pluck('value')->all())
            ->toEqualCanonicalizing(['+966112345678', '+966551234567', 'info@acmesoft.sa']);

        $jeddah = Company::where('name', 'Jeddah Digital')->firstOrFail();
        expect($jeddah->city)->toBe('جدة') // from coordinates
            ->and($jeddah->contacts()->first()->is_whatsapp)->toBeTrue();

        $fb = Company::where('name', 'FB Only Tech')->firstOrFail();
        expect($fb->website)->toBeNull()->and($fb->domain)->toBeNull(); // blocked "website" dropped

        // Second run: served from cache — Overpass is queried once per day.
        $this->artisan('outreach:discover')->assertSuccessful();
        Http::assertSentCount(1);
        expect(Company::count())->toBe(3);
    });

    test('the query covers Saudi Arabia and the office tags', function () {
        $query = app(OverpassSource::class)->query();

        expect($query)->toContain('"ISO3166-1"="SA"')
            ->toContain('["office"="it"]')
            ->toContain('company|telecommunication')
            ->toContain('برمج')
            ->toContain('[out:json][timeout:90]');
    });

    test('an Overpass outage is logged and does not fail discovery', function () {
        Http::fake(['overpass.test/*' => Http::response('busy', 504)]);

        $this->artisan('outreach:discover')->assertSuccessful();

        $run = SearchRun::latest('id')->first();
        expect($run->finished_at)->not->toBeNull()
            ->and(collect($run->errors)->pluck('message')->implode(' '))->toContain('Overpass HTTP 504');
    });
});

describe('Serper', function () {
    beforeEach(function () {
        SearchQuery::create(['query' => 'software company Riyadh', 'group' => QueryGroup::Discovery, 'language' => 'en', 'city' => 'riyadh']);
    });

    test('is disabled without an API key', function () {
        expect(app(SerperSource::class)->isEnabled())->toBeFalse();
    });

    test('stores organic results when a key is set', function () {
        config(['outreach.serper.api_key' => 'test-key']);
        Http::fake(['google.serper.dev/*' => Http::response(['organic' => [
            ['title' => 'Nova Systems | Software House', 'link' => 'https://www.novasystems.sa/', 'snippet' => 'Riyadh software'],
        ]])]);

        $this->artisan('outreach:discover')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-API-KEY', 'test-key') && $r['gl'] === 'sa');
        expect(Company::where('domain', 'novasystems.sa')->first()?->source)->toBe(CompanySource::Serper);
    });

    test('auto-disables itself when credits run out and discovery continues', function () {
        config(['outreach.serper.api_key' => 'test-key', 'outreach.overpass.url' => 'https://overpass.test/api']);
        Http::fake([
            'google.serper.dev/*' => Http::response(['message' => 'Not enough credits', 'statusCode' => 400], 400),
            'overpass.test/*' => Http::response(['elements' => [
                ['type' => 'node', 'id' => 9, 'lat' => 24.7, 'lon' => 46.7, 'tags' => ['office' => 'it', 'name' => 'Still Works', 'phone' => '0551112233']],
            ]]),
        ]);

        $this->artisan('outreach:discover')->assertSuccessful();

        expect(app(SourceState::class)->isDisabled('serper'))->toBeTrue()
            ->and(app(SerperSource::class)->isEnabled())->toBeFalse()
            ->and(Company::where('name', 'Still Works')->exists())->toBeTrue()
            ->and(collect(SearchRun::latest('id')->first()->errors)->pluck('message')->implode(' '))->toContain('credits');

        // Next run doesn't even call Serper.
        Http::fake(['google.serper.dev/*' => fn () => throw new RuntimeException('should not be called')]);
        $this->artisan('outreach:discover')->assertSuccessful();

        $this->artisan('outreach:source-enable', ['source' => 'serper'])->assertSuccessful();
        expect(app(SourceState::class)->isDisabled('serper'))->toBeFalse();
    });
});
