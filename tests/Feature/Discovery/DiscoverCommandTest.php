<?php

use App\Enums\QueryGroup;
use App\Enums\SearchRunType;
use App\Models\Company;
use App\Models\SearchQuery;
use App\Models\SearchRun;
use Database\Seeders\BlockedDomainSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    Queue::fake(); // discovery only; the crawl/AI pipeline has its own tests
    $this->seed(BlockedDomainSeeder::class);
    // pool_target 0 = pool "full": no automatic query variations (QueryRotation has its own tests).
    config(['outreach.searxng.url' => 'http://searx.test', 'outreach.pool_target' => 0]);
});

function searx(array $results): array
{
    return ['query' => 'x', 'number_of_results' => count($results), 'results' => $results, 'unresponsive_engines' => []];
}

function hit(string $url, string $title, string $content = ''): array
{
    return ['url' => $url, 'title' => $title, 'content' => $content, 'engine' => 'google'];
}

/** Fake SearXNG: responses keyed by a substring of the query. */
function fakeSearx(array $byQuery): void
{
    Http::fake(['searx.test/*' => function (Request $request) use ($byQuery) {
        foreach ($byQuery as $needle => $results) {
            if (str_contains($request['q'], $needle)) {
                return is_int($results) ? Http::response('error', $results) : Http::response(searx($results));
            }
        }

        return Http::response(searx([]));
    }]);
}

test('stores real companies, skips blocked domains, resolves job-board hits, no duplicates', function () {
    SearchQuery::create(['query' => 'شركة برمجيات الرياض', 'group' => QueryGroup::Discovery, 'language' => 'ar', 'city' => 'riyadh']);
    SearchQuery::create(['query' => 'hiring PHP developer Riyadh', 'group' => QueryGroup::Hiring, 'language' => 'en', 'city' => 'riyadh']);

    fakeSearx([
        'شركة برمجيات الرياض' => [
            hit('https://www.acmesoft.com.sa/', 'Acme Soft | Software Development in Riyadh', 'We build web apps'),
            hit('https://blog.acmesoft.com.sa/post', 'Acme Soft blog'),                 // same company → no dupe
            hit('https://www.facebook.com/acme', 'Acme Soft - Facebook'),              // blocked: social
            hit('https://moe.gov.sa/ar', 'وزارة التعليم'),                                // blocked: gov (suffix)
            hit('https://ar.wikipedia.org/wiki/x', 'برمجيات - ويكيبيديا'),                // blocked: reference
        ],
        'hiring PHP developer Riyadh' => [
            hit('https://sa.linkedin.com/jobs/view/123', 'Nova Systems hiring PHP Developer in Riyadh | LinkedIn', 'Nova Systems is looking for…'),
            hit('https://www.bayt.com/en/saudi-arabia/jobs/', '500+ PHP jobs in Saudi Arabia'),       // unparseable listing
            hit('https://careers.tamkeen.sa/jobs/php', 'PHP Developer - Tamkeen', 'Join Tamkeen in Jeddah'),
        ],
        'Nova Systems السعودية الموقع الرسمي' => [
            hit('https://www.linkedin.com/company/nova', 'Nova Systems | LinkedIn'),
            hit('https://novasystems.sa/', 'Nova Systems - Home'),
        ],
    ]);

    $this->artisan('outreach:discover')->assertSuccessful();

    expect(Company::count())->toBe(3);

    $acme = Company::where('domain', 'acmesoft.com.sa')->firstOrFail();
    expect($acme->name)->toBe('Acme Soft')
        ->and($acme->website)->toBe('https://www.acmesoft.com.sa')
        ->and($acme->city)->toBe('الرياض');

    $nova = Company::where('domain', 'novasystems.sa')->firstOrFail();
    expect($nova->name)->toBe('Nova Systems')
        ->and($nova->job_post_url)->toBe('https://sa.linkedin.com/jobs/view/123');

    $tamkeen = Company::where('domain', 'tamkeen.sa')->firstOrFail();
    expect($tamkeen->job_post_url)->toBe('https://careers.tamkeen.sa/jobs/php')
        ->and($tamkeen->website)->toBe('https://tamkeen.sa')
        ->and($tamkeen->city)->toBe('جدة'); // detected from the snippet, overrides query city

    $run = SearchRun::latest('id')->first();
    expect($run->type)->toBe(SearchRunType::Discover)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->stats['new_companies'])->toBe(3)
        ->and($run->stats['searxng']['blocked'])->toBe(3)
        ->and($run->stats['searxng']['job_board_unparsed'])->toBe(1)
        ->and($run->stats['searxng']['official_site_found'])->toBe(1)
        ->and(SearchQuery::whereNull('last_run_at')->count())->toBe(0);

    // Second run the same day: served from cache, nothing new, still no duplicates.
    $this->artisan('outreach:discover')->assertSuccessful();
    expect(Company::count())->toBe(3)
        ->and(SearchRun::latest('id')->first()->stats['new_companies'])->toBe(0);
});

test('respects the per-run search budget and rotates to least recently run queries', function () {
    foreach (range(1, 5) as $i) {
        SearchQuery::create(['query' => "query {$i}", 'group' => QueryGroup::Discovery, 'language' => 'en',
            'last_run_at' => $i === 1 ? now() : null]);
    }
    fakeSearx(['query' => [hit('https://x'.uniqid().'.com/', 'X')]]);

    $this->artisan('outreach:discover', ['--limit' => 3])->assertSuccessful();

    Http::assertSentCount(3);
    // "query 1" ran most recently, so it is skipped in favor of never-run queries.
    expect(SearchQuery::where('query', 'query 1')->first()->last_run_at->isToday())->toBeTrue()
        ->and(SearchQuery::whereNull('last_run_at')->count())->toBe(1);
});

test('waits 5-15 s between live searches', function () {
    foreach (range(1, 3) as $i) {
        SearchQuery::create(['query' => "q{$i}", 'group' => QueryGroup::Discovery, 'language' => 'en']);
    }
    fakeSearx(['q' => [hit('https://a.com/', 'A')]]);

    $this->artisan('outreach:discover')->assertSuccessful();

    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 5 && $duration->totalSeconds <= 15, times: 2);
});

test('stops gracefully after repeated SearXNG errors and logs them to the run', function () {
    foreach (range(1, 6) as $i) {
        SearchQuery::create(['query' => "failing {$i}", 'group' => QueryGroup::Discovery, 'language' => 'en']);
    }
    fakeSearx(['failing' => 502]);

    $this->artisan('outreach:discover')->assertSuccessful();

    $run = SearchRun::latest('id')->first();
    expect($run->finished_at)->not->toBeNull()
        ->and($run->stats['searxng']['queries_run'])->toBe(3)               // max_consecutive_failures
        ->and(collect($run->errors)->pluck('message')->implode("\n"))
        ->toContain('SearXNG HTTP 502')
        ->toContain('will continue tomorrow')
        // Failed queries are not marked as run: all 6 stay at the front of the rotation for next time.
        ->and(SearchQuery::whereNull('last_run_at')->count())->toBe(6);
});

test('a second concurrent run is skipped', function () {
    cache()->lock(\App\Services\Discovery\DiscoveryService::LOCK, 60)->get();

    $this->artisan('outreach:discover')->expectsOutputToContain('already in progress')->assertSuccessful();
    expect(SearchRun::count())->toBe(0);
});
