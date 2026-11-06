<?php

use App\Enums\CompanyStatus;
use App\Enums\QueryGroup;
use App\Models\Company;
use App\Models\SearchQuery;
use Database\Seeders\BlockedDomainSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

test('discover → enrich → analyze: a real-looking company ends up qualified with contacts', function () {
    Sleep::fake();
    $this->seed([BlockedDomainSeeder::class, SettingsSeeder::class]);
    config([
        'outreach.searxng.url' => 'http://searx.test',
        'outreach.llm.gemini.api_key' => 'k',
        'queue.default' => 'sync', // run the whole chain inline
    ]);
    SearchQuery::create(['query' => 'شركة برمجيات الرياض', 'group' => QueryGroup::Discovery, 'language' => 'ar', 'city' => 'riyadh']);

    $gemini = json_encode([
        'is_software_company' => true, 'is_hiring_devs' => false, 'relevance' => 82,
        'description_ar' => 'شركة سعودية لتطوير المواقع والتطبيقات.', 'services' => ['تطوير المواقع'],
        'tech_hints' => ['Laravel'], 'city' => 'الرياض', 'reason_ar' => 'شركة برمجيات',
    ], JSON_UNESCAPED_UNICODE);

    Http::fake(function (Request $request) use ($gemini) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'searx.test') => Http::response(['results' => [
                ['url' => 'https://www.acmesoft.sa/', 'title' => 'Acme Soft | تطوير برمجيات', 'content' => 'Riyadh'],
            ]]),
            str_ends_with($url, 'robots.txt') => Http::response('', 404),
            str_contains($url, 'acmesoft.sa/contact') => Http::response('<p>Mobile 0551234567</p><a href="https://wa.me/966551234567">wa</a>', 200, ['Content-Type' => 'text/html']),
            str_contains($url, 'acmesoft.sa') => Http::response('<html><body><p>'.str_repeat('Laravel software house. ', 30).'</p><a href="/contact">Contact</a></body></html>', 200, ['Content-Type' => 'text/html']),
            str_contains($url, 'generativelanguage') => Http::response(['candidates' => [['content' => ['parts' => [['text' => $gemini]]]]]]),
            default => Http::response('', 404),
        };
    });

    $this->artisan('outreach:discover')->assertSuccessful();

    $company = Company::where('domain', 'acmesoft.sa')->firstOrFail();
    expect($company->status)->toBe(CompanyStatus::Qualified)
        ->and($company->llm_driver)->toBe('gemini')
        ->and($company->contacts()->where('is_whatsapp', true)->value('value'))->toBe('+966551234567')
        ->and($company->description_ar)->toContain('تطوير');
});
