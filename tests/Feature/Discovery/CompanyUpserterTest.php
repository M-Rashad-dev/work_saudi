<?php

use App\DTO\DiscoveredCompanyDTO;
use App\Enums\CompanySource;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Discovery\CompanyUpserter;

function dto(array $overrides = []): DiscoveredCompanyDTO
{
    return new DiscoveredCompanyDTO(...array_merge([
        'name' => 'Acme Soft',
        'source' => CompanySource::Searxng,
        'website' => 'https://acmesoft.com.sa',
        'domain' => 'acmesoft.com.sa',
        'city' => 'الرياض',
        'snippet' => 'Software house in Riyadh',
    ], $overrides));
}

test('creates a new company with normalized name and maps link', function () {
    [$company, $created] = app(CompanyUpserter::class)->upsert(dto());

    expect($created)->toBeTrue()
        ->and($company->status)->toBe(CompanyStatus::New)
        ->and($company->normalized_name)->toBe('acme soft')
        ->and($company->google_maps_url)->toBe('https://www.google.com/maps/search/?api=1&query=Acme%20Soft%20%D8%A7%D9%84%D8%B1%D9%8A%D8%A7%D8%B6');
});

test('dedupes by domain, even from a different subdomain/name', function () {
    $upserter = app(CompanyUpserter::class);
    $upserter->upsert(dto());
    [, $created] = $upserter->upsert(dto(['name' => 'اكمي سوفت', 'website' => 'https://careers.acmesoft.com.sa', 'domain' => null]));

    expect($created)->toBeFalse()->and(Company::count())->toBe(1);
});

test('dedupes by normalized name + city when there is no domain, then attaches the domain later', function () {
    $upserter = app(CompanyUpserter::class);
    $upserter->upsert(dto(['name' => 'شركة أكمي للتقنية', 'website' => null, 'domain' => null, 'jobPostUrl' => 'https://bayt.com/job/1']));
    [$company, $created] = $upserter->upsert(dto(['name' => 'شركه اكمي للتقنيه المحدودة', 'website' => 'https://akmi.sa', 'domain' => 'akmi.sa']));

    expect($created)->toBeFalse()
        ->and(Company::count())->toBe(1)
        ->and($company->domain)->toBe('akmi.sa')
        ->and($company->job_post_url)->toBe('https://bayt.com/job/1');
});

test('same name in a different city with a different domain is a different company', function () {
    $upserter = app(CompanyUpserter::class);
    $upserter->upsert(dto());
    $upserter->upsert(dto(['domain' => 'acmesoft-jeddah.com', 'website' => 'https://acmesoft-jeddah.com', 'city' => 'جدة']));

    expect(Company::count())->toBe(2);
});

test('merging never changes status, so shown/rejected companies never come back', function () {
    $company = Company::factory()->create(['domain' => 'acmesoft.com.sa', 'status' => CompanyStatus::Shown, 'job_post_url' => null]);

    app(CompanyUpserter::class)->upsert(dto(['jobPostUrl' => 'https://linkedin.com/jobs/view/9', 'isHiringSignal' => true]));

    $company->refresh();
    expect($company->status)->toBe(CompanyStatus::Shown)
        ->and($company->job_post_url)->toBe('https://linkedin.com/jobs/view/9')
        ->and(Company::count())->toBe(1);
});
