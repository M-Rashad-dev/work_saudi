<?php

use App\Enums\CompanyStatus;
use App\Enums\ContactLabel;
use App\Enums\QueryGroup;
use App\Enums\SearchRunType;
use App\Enums\SettingKey;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DailyBatch;
use App\Models\DailyBatchItem;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use App\Services\Batch\BatchBuilder;
use App\Services\Batch\ContactSelector;
use App\Services\Batch\MessageRenderer;
use App\Services\Discovery\QueryRotation;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;

/** A qualified company with the given kind of contact. */
function qualified(string $contact = 'whatsapp', array $attributes = []): Company
{
    $company = Company::factory()->qualified($attributes['relevance'] ?? 70)->create($attributes);
    match ($contact) {
        'whatsapp' => Contact::factory()->whatsapp()->create(['company_id' => $company->id]),
        'mobile' => Contact::factory()->mobile()->create(['company_id' => $company->id]),
        'email' => Contact::factory()->create(['company_id' => $company->id]),
        'landline' => Contact::factory()->landline()->create(['company_id' => $company->id]),
    };

    return $company;
}

function build(?string $date = null): \App\Services\Batch\BatchResult
{
    return app(BatchBuilder::class)->build($date ? Carbon::parse($date) : null);
}

test('picks exactly the batch size and marks the companies shown', function () {
    foreach (range(1, 15) as $_) {
        qualified();
    }

    $result = build();

    expect($result->batch->items()->count())->toBe(10)
        ->and($result->added)->toBe(10)
        ->and(Company::where('status', CompanyStatus::Shown)->count())->toBe(10)
        ->and($result->batch->items()->pluck('position')->all())->toBe(range(1, 10));
});

test('orders WhatsApp-capable first, then hiring, then relevance', function () {
    $emailHiringTop = qualified('email', ['is_hiring_devs' => true, 'relevance' => 99]);
    $waLow = qualified('whatsapp', ['relevance' => 55]);
    $waHiring = qualified('mobile', ['is_hiring_devs' => true, 'relevance' => 60]);
    $waHigh = qualified('whatsapp', ['relevance' => 95]);

    $order = build()->batch->items()->with('company')->get()->pluck('company.id')->all();

    expect($order)->toBe([$waHiring->id, $waHigh->id, $waLow->id, $emailHiringTop->id]);
});

test('fills with email-only companies when there are not enough WhatsApp numbers; landline-only never', function () {
    foreach (range(1, 4) as $_) {
        qualified('whatsapp');
    }
    foreach (range(1, 8) as $_) {
        qualified('email');
    }
    $landline = qualified('landline');

    $items = build()->batch->items()->with('company')->get();

    expect($items)->toHaveCount(10)
        ->and($items->pluck('company_id'))->not->toContain($landline->id)
        ->and($landline->fresh()->status)->toBe(CompanyStatus::Qualified);
});

test('a company is never repeated across days', function () {
    foreach (range(1, 25) as $_) {
        qualified();
    }

    $day1 = build('2026-11-01')->batch->items()->pluck('company_id');
    $day2 = build('2026-11-02')->batch->items()->pluck('company_id');
    $day3 = build('2026-11-03')->batch->items()->pluck('company_id');

    expect($day1->intersect($day2))->toBeEmpty()
        ->and($day1->merge($day2)->intersect($day3))->toBeEmpty()
        ->and($day3)->toHaveCount(5) // only 25 existed
        ->and(DailyBatchItem::count())->toBe(25)
        ->and(DailyBatchItem::distinct()->count('company_id'))->toBe(25);
});

test('only qualified companies are picked (not rejected, enriched, new or already shown)', function () {
    $ok = qualified();
    foreach ([CompanyStatus::Rejected, CompanyStatus::Enriched, CompanyStatus::New, CompanyStatus::Shown] as $status) {
        $c = qualified();
        $c->update(['status' => $status]);
    }

    expect(build()->batch->items()->pluck('company_id')->all())->toBe([$ok->id]);
});

test('running twice on the same day tops up instead of duplicating', function () {
    foreach (range(1, 6) as $_) {
        qualified();
    }
    $first = build('2026-11-05');
    expect($first->total)->toBe(6)->and($first->isComplete())->toBeFalse();

    foreach (range(1, 6) as $_) {
        qualified();
    }
    $second = build('2026-11-05');

    expect(DailyBatch::count())->toBe(1)
        ->and($second->added)->toBe(4)
        ->and($second->total)->toBe(10)
        ->and($second->batch->items()->pluck('position')->all())->toBe(range(1, 10));

    expect(build('2026-11-05')->added)->toBe(0);
});

test('a short batch is logged as an error for the dashboard banner', function () {
    qualified();

    build();

    $run = SearchRun::where('type', SearchRunType::BuildBatch)->latest('id')->first();
    expect($run->stats['total'])->toBe(1)
        ->and($run->errors[0]['message'])->toContain('الدفعة ناقصة');
});

test('batch size comes from settings', function () {
    app(SettingsRepository::class)->set(SettingKey::BatchSize, 3);
    foreach (range(1, 5) as $_) {
        qualified();
    }

    expect(build()->total)->toBe(3);
});

test('the build-batch command works', function () {
    qualified();

    $this->artisan('outreach:build-batch', ['--date' => '2026-11-06'])->assertSuccessful();

    expect(DailyBatch::whereDate('date', '2026-11-06')->exists())->toBeTrue();
});

describe('ContactSelector', function () {
    test('WhatsApp priority: wa.me link > HR/careers mobile > any mobile; landline never', function () {
        $company = Company::factory()->create();
        $landline = Contact::factory()->landline()->create(['company_id' => $company->id]);
        $mobile = Contact::factory()->mobile()->create(['company_id' => $company->id]);
        $hrMobile = Contact::factory()->mobile()->create(['company_id' => $company->id, 'label' => ContactLabel::Hr]);
        $selector = app(ContactSelector::class);

        expect($selector->whatsapp($company->contacts()->get())->id)->toBe($hrMobile->id);

        $wa = Contact::factory()->whatsapp()->create(['company_id' => $company->id]);
        expect($selector->whatsapp($company->contacts()->get())->id)->toBe($wa->id);

        expect($selector->whatsapp(collect([$landline])))->toBeNull();
    });

    test('email priority: hr > careers > info > others', function () {
        $company = Company::factory()->create();
        foreach (['ahmed@x.sa', 'info@x.sa', 'careers@x.sa', 'hr@x.sa'] as $email) {
            Contact::factory()->create(['company_id' => $company->id, 'value' => $email]);
        }

        expect(app(ContactSelector::class)->email($company->contacts()->get())->value)->toBe('hr@x.sa');
    });
});

describe('MessageRenderer', function () {
    test('replaces placeholders and builds encoded wa.me and mailto links', function () {
        $settings = app(SettingsRepository::class);
        $settings->setMany(['my_name' => 'محمد رشاد', 'cv_url' => 'https://cv.example/me', 'portfolio_url' => 'https://me.dev']);
        $company = Company::factory()->create(['name' => 'شركة أكمي']);
        $renderer = app(MessageRenderer::class);

        $message = $renderer->message($company);
        expect($message)->toContain('أنا محمد رشاد')
            ->toContain('تواصلت مع شركة أكمي')
            ->toContain('https://cv.example/me')
            ->toContain('https://me.dev')
            ->not->toContain('{');

        $wa = $renderer->whatsappUrl('+966551234567', $company);
        expect($wa)->toStartWith('https://wa.me/966551234567?text=')
            ->and(rawurldecode(substr($wa, strpos($wa, '=') + 1)))->toBe($message);

        $mail = $renderer->mailtoUrl('hr@x.sa', $company);
        expect($mail)->toStartWith('mailto:hr@x.sa?subject=')
            ->not->toContain('+')
            ->and(rawurldecode($mail))->toContain('محمد رشاد');
    });
});

describe('pool rotation', function () {
    test('adds fresh query variations for the least-covered cities when the pool is low', function () {
        SearchQuery::create(['query' => 'شركة برمجيات الرياض', 'group' => QueryGroup::Discovery, 'language' => 'ar', 'city' => 'riyadh', 'last_run_at' => now()]);

        $added = app(QueryRotation::class)->expandIfNeeded(12);

        $new = SearchQuery::whereNull('last_run_at')->get();
        expect($added)->toBe(12)
            ->and($new)->toHaveCount(12)
            ->and($new->pluck('city')->unique()->count())->toBe(9) // spread across all cities
            ->and($new->where('city', 'riyadh')->count())->toBeLessThanOrEqual(1)
            ->and(SearchQuery::where('query', 'شركة برمجيات الرياض')->count())->toBe(1); // no duplicates
    });

    test('does nothing when the pool is healthy', function () {
        foreach (range(1, 30) as $_) {
            qualified();
        }

        expect(app(QueryRotation::class)->expandIfNeeded(25))->toBe(0)
            ->and(SearchQuery::count())->toBe(0);
    });

    test('does nothing when enough never-run queries remain', function () {
        foreach (range(1, 5) as $i) {
            SearchQuery::create(['query' => "q{$i}", 'group' => QueryGroup::Discovery, 'language' => 'en']);
        }

        expect(app(QueryRotation::class)->expandIfNeeded(5))->toBe(0);
    });
});
