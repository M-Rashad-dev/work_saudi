<?php

use App\Enums\BatchAction;
use App\Enums\CompanyStatus;
use App\Enums\QueryGroup;
use App\Enums\SearchRunType;
use App\Jobs\EnrichCompanyJob;
use App\Jobs\RunDiscoveryJob;
use App\Models\BlockedDomain;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DailyBatch;
use App\Models\DailyBatchItem;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use App\Models\User;
use App\Services\Batch\BatchBuilder;
use App\Services\Settings\SettingsRepository;
use Database\Seeders\BlockedDomainSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([SettingsSeeder::class, BlockedDomainSeeder::class]);
    $this->actingAs(User::factory()->create());
});

function batchToday(int $whatsapp = 2, int $email = 1): DailyBatch
{
    // Note: range(1, 0) is [1, 0] in PHP — use plain for-loops.
    for ($i = 0; $i < $whatsapp; $i++) {
        $c = Company::factory()->qualified()->create();
        Contact::factory()->whatsapp()->create(['company_id' => $c->id]);
    }
    for ($i = 0; $i < $email; $i++) {
        $c = Company::factory()->qualified()->create();
        Contact::factory()->create(['company_id' => $c->id, 'value' => "hr{$c->id}@x.sa"]);
    }

    return app(BatchBuilder::class)->build()->batch;
}

describe('اليوم', function () {
    test('shows today\'s cards with WhatsApp and mailto links, progress and stats', function () {
        app(SettingsRepository::class)->setMany(['my_name' => 'محمد']);
        batchToday();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('batch.items', 3)
            ->where('batch.items.0.company.whatsapp_url', fn ($url) => str_starts_with($url, 'https://wa.me/9665') && str_contains(rawurldecode($url), 'محمد'))
            ->where('batch.items.0.company.email_only', false)
            ->where('batch.items.2.company.email_only', true)
            ->where('batch.items.2.company.whatsapp_url', null)
            ->where('batch.items.2.company.mailto_url', fn ($url) => str_starts_with($url, 'mailto:hr'))
            ->where('progress.sent', 0)
            ->where('progress.total', 10)
            ->has('stats')
            ->has('banners'));
    });

    test('a company with several emails and phones renders, emails ordered hr > careers > info', function () {
        $c = Company::factory()->qualified()->create();
        Contact::factory()->whatsapp()->create(['company_id' => $c->id]);
        Contact::factory()->mobile()->create(['company_id' => $c->id]);
        foreach (['info@multi.sa', 'ahmed@multi.sa', 'hr@multi.sa', 'careers@multi.sa'] as $email) {
            Contact::factory()->create(['company_id' => $c->id, 'value' => $email]);
        }
        app(BatchBuilder::class)->build();

        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('batch.items.0.company.phones', 2)
            ->where('batch.items.0.company.emails', fn ($emails) => collect($emails)->pluck('value')->all()
                === ['hr@multi.sa', 'careers@multi.sa', 'info@multi.sa', 'ahmed@multi.sa']));

        $this->get(route('companies.show', $c))->assertOk();
        $this->get('/archive')->assertOk();
    });

    test('empty state and banners when nothing has run yet', function () {
        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('batch', null)
            ->where('banners', fn ($banners) => collect($banners)->pluck('message')->join(' ') !== ''
                && collect($banners)->contains(fn ($b) => str_contains($b['message'], 'لم يتم تشغيل البحث'))
                && collect($banners)->contains(fn ($b) => str_contains($b['message'], 'الشركات المؤهلة المتاحة'))));
    });

    test('a failed discovery run shows an error banner', function () {
        $run = SearchRun::start(SearchRunType::Discover);
        $run->addError('SearXNG HTTP 502');
        $run->finish(['new_companies' => 0]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('banners', fn ($b) => collect($b)->contains(fn ($x) => $x['level'] === 'error' && str_contains($x['detail'] ?? '', 'SearXNG HTTP 502'))));
    });

    test('marking a card updates progress and stats; clicking again undoes', function () {
        $item = batchToday()->items()->first();

        $this->patch(route('batch-items.update', $item), ['action' => 'sent'])->assertRedirect();
        expect($item->fresh()->action)->toBe(BatchAction::Sent)->and($item->fresh()->action_at)->not->toBeNull();

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('progress.sent', 1)->where('stats.sent', 1));

        $this->patch(route('batch-items.update', $item), ['action' => 'replied']);
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('stats.replies', 1)->where('stats.reply_rate', 100));

        $this->patch(route('batch-items.update', $item), ['action' => 'pending']);
        expect($item->fresh()->action)->toBe(BatchAction::Pending)->and($item->fresh()->action_at)->toBeNull();
    });

    test('a note can be saved and an invalid action is rejected', function () {
        $item = batchToday()->items()->first();

        $this->patch(route('batch-items.update', $item), ['note' => 'طلبوا CV'])->assertRedirect();
        expect($item->fresh()->note)->toBe('طلبوا CV');

        $this->patch(route('batch-items.update', $item), ['action' => 'deleted'])->assertSessionHasErrors('action');
    });

    test('"جهّز دفعة اليوم" builds the batch', function () {
        $c = Company::factory()->qualified()->create();
        Contact::factory()->whatsapp()->create(['company_id' => $c->id]);

        $this->post(route('batches.store'))->assertRedirect()->assertSessionHas('success');

        expect(DailyBatch::whereDate('date', today())->first()->items()->count())->toBe(1);
    });

    test('"شغّل البحث الآن" queues discovery — never runs it inline', function () {
        Queue::fake();

        $this->post(route('discover'))->assertRedirect()->assertSessionHas('success');

        Queue::assertPushed(RunDiscoveryJob::class);
    });
});

describe('الأرشيف', function () {
    test('lists past batches and filters by action', function () {
        $batch = batchToday(3, 0);
        $batch->items()->first()->update(['action' => BatchAction::Replied]);

        $this->get('/archive')->assertInertia(fn (Assert $page) => $page
            ->component('Archive/Index')
            ->has('batches.data', 1)
            ->has('batches.data.0.items', 3));

        $this->get('/archive?action=replied')->assertInertia(fn (Assert $page) => $page
            ->where('filters.action', 'replied')
            ->has('batches.data.0.items', 1)
            ->where('batches.data.0.items.0.action', 'replied'));

        $this->get('/archive?action=skipped')->assertInertia(fn (Assert $page) => $page->has('batches.data', 0));
    });
});

describe('الشركات', function () {
    test('lists and filters companies', function () {
        $wa = Company::factory()->qualified(90)->create(['city' => 'جدة', 'name' => 'Alpha Soft']);
        Contact::factory()->whatsapp()->create(['company_id' => $wa->id]);
        Company::factory()->create(['city' => 'الرياض', 'status' => CompanyStatus::Rejected, 'relevance' => 10]);

        $this->get('/companies')->assertInertia(fn (Assert $page) => $page->component('Companies/Index')->has('companies.data', 2));
        $this->get('/companies?city=جدة')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1)->where('companies.data.0.name', 'Alpha Soft'));
        $this->get('/companies?has_whatsapp=1')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1)->where('companies.data.0.has_whatsapp', true));
        $this->get('/companies?has_whatsapp=0')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));
        $this->get('/companies?status=rejected')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));
        $this->get('/companies?min_relevance=50')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));
        $this->get('/companies?q=alpha')->assertInertia(fn (Assert $page) => $page->has('companies.data', 1));
    });

    test('detail page shows contacts, history and buttons', function () {
        $item = batchToday(1, 0)->items()->first();

        $this->get(route('companies.show', $item->company_id))->assertInertia(fn (Assert $page) => $page
            ->component('Companies/Show')
            ->has('company.phones', 1)
            ->where('company.batch_item.action', 'pending')
            ->where('company.whatsapp_url', fn ($u) => str_starts_with($u, 'https://wa.me/'))
            ->has('history', 4)); // discovered, enriched, analyzed, shown in batch
    });

    test('a company can be added manually; contacts are normalized and it is crawled + force-analyzed', function () {
        Queue::fake();

        $this->post(route('companies.store'), [
            'name' => 'شركة يدوية', 'website' => 'manual-co.sa', 'city' => 'الرياض',
            'whatsapp' => '٠٥٥ ١١١ ٢٢٣٣', 'email' => 'HR@manual-co.sa',
        ])->assertRedirect()->assertSessionHas('success');

        $company = Company::where('domain', 'manual-co.sa')->firstOrFail();
        expect($company->website)->toBe('https://manual-co.sa')
            ->and($company->source->value)->toBe('manual')
            ->and($company->contacts()->pluck('value')->all())->toEqualCanonicalizing(['+966551112233', 'hr@manual-co.sa'])
            ->and($company->contacts()->where('is_whatsapp', true)->exists())->toBeTrue();

        Queue::assertPushed(EnrichCompanyJob::class, fn ($job) => $job->companyId === $company->id && $job->forceAnalysis);
    });

    test('adding a duplicate redirects to the existing company', function () {
        Queue::fake();
        $existing = Company::factory()->create(['domain' => 'dupe.sa']);

        $this->post(route('companies.store'), ['name' => 'Other name', 'website' => 'https://www.dupe.sa'])
            ->assertRedirect(route('companies.show', $existing))
            ->assertSessionHas('error');

        expect(Company::count())->toBe(1);
        Queue::assertNothingPushed();
    });

    test('manual add validates input', function () {
        $this->post(route('companies.store'), ['name' => '', 'email' => 'not-an-email', 'city' => 'Paris'])
            ->assertSessionHasErrors(['name', 'email', 'city']);
    });
});

describe('البحث', function () {
    test('queries can be added, edited, toggled and deleted', function () {
        $this->post(route('search.queries.store'), ['query' => '  شركة برمجيات   أبها ', 'group' => 'discovery', 'language' => 'ar', 'city' => 'abha'])
            ->assertSessionHasNoErrors();
        $q = SearchQuery::firstOrFail();
        expect($q->query)->toBe('شركة برمجيات أبها')->and($q->is_active)->toBeTrue();

        // duplicate (same text + language) rejected
        $this->post(route('search.queries.store'), ['query' => 'شركة برمجيات أبها', 'group' => 'discovery', 'language' => 'ar'])
            ->assertSessionHasErrors('query');

        $this->patch(route('search.queries.update', $q), ['is_active' => false])->assertSessionHasNoErrors();
        expect($q->fresh()->is_active)->toBeFalse();

        $this->patch(route('search.queries.update', $q), ['query' => 'software company Abha', 'language' => 'en', 'group' => 'hiring'])
            ->assertSessionHasNoErrors();
        expect($q->fresh()->group)->toBe(QueryGroup::Hiring);

        $this->delete(route('search.queries.destroy', $q))->assertRedirect();
        expect(SearchQuery::count())->toBe(0);
    });

    test('shows queries and recent runs with errors', function () {
        SearchQuery::create(['query' => 'x company', 'group' => QueryGroup::Discovery, 'language' => 'en']);
        $run = SearchRun::start(SearchRunType::Discover);
        $run->addError('boom');
        $run->finish(['new_companies' => 2, 'new_company_ids' => [1, 2]]);

        $this->get('/search')->assertInertia(fn (Assert $page) => $page
            ->component('Search/Index')
            ->has('queries', 1)
            ->has('runs', 1)
            ->where('runs.0.errors.0', 'boom')
            ->where('runs.0.stats.new_companies', 2)
            ->missing('runs.0.stats.new_company_ids'));
    });
});

describe('الإعدادات', function () {
    test('saves settings and validates them', function () {
        $payload = [
            'my_name' => 'محمد رشاد', 'cv_url' => 'https://cv.example.com', 'portfolio_url' => '',
            'message_template' => 'مرحبا {company_name}', 'email_subject' => 'Hi', 'email_body' => 'Body',
            'batch_size' => 12, 'batch_time' => '08:30', 'min_relevance' => 60, 'llm_drivers' => ['heuristic', 'gemini'],
        ];

        $this->put(route('settings.update'), $payload)->assertSessionHasNoErrors()->assertSessionHas('success');

        $s = app(SettingsRepository::class);
        expect($s->batchSize())->toBe(12)
            ->and($s->batchTime())->toBe('08:30')
            ->and($s->minRelevance())->toBe(60)
            ->and($s->llmDrivers())->toBe(['heuristic', 'gemini']);

        $this->put(route('settings.update'), [...$payload, 'batch_size' => 0, 'batch_time' => '25:00', 'cv_url' => 'nope', 'llm_drivers' => ['chatgpt']])
            ->assertSessionHasErrors(['batch_size', 'batch_time', 'cv_url', 'llm_drivers.0']);
    });

    test('blocked domains can be added (normalized) and removed, refreshing the cache', function () {
        BlockedDomain::cachedMap(); // warm cache

        $this->post(route('settings.blocked-domains.store'), ['domain' => 'https://WWW.Spam-Directory.com/list'])->assertSessionHasNoErrors();
        expect(BlockedDomain::cachedMap())->toHaveKey('spam-directory.com');

        $this->post(route('settings.blocked-domains.store'), ['domain' => 'spam-directory.com'])->assertSessionHasErrors('domain');

        $this->delete(route('settings.blocked-domains.destroy', BlockedDomain::where('domain', 'spam-directory.com')->first()));
        expect(BlockedDomain::cachedMap())->not->toHaveKey('spam-directory.com');
    });

    test('settings page renders with drivers and sources', function () {
        $this->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Edit')
            ->has('drivers', 3)
            ->where('drivers.2.name', 'heuristic')
            ->where('drivers.2.available', true)
            ->has('blockedDomains')
            ->where('settings.batch_size', 10));
    });

    test('a disabled source can be re-enabled', function () {
        app(\App\Services\Discovery\SourceState::class)->disable('serper', 'credits');

        $this->delete(route('settings.sources.enable', 'serper'))->assertRedirect();
        expect(app(\App\Services\Discovery\SourceState::class)->isDisabled('serper'))->toBeFalse();

        $this->delete(route('settings.sources.enable', 'unknown'))->assertNotFound();
    });
});
