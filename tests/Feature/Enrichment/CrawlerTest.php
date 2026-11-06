<?php

use App\Contracts\JsRendererInterface;
use App\Enums\CompanyStatus;
use App\Jobs\AnalyzeCompanyJob;
use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Enrichment\RobotsTxt;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Sleep::fake();
    $this->sleeps = [];
    Sleep::whenFakingSleep(fn ($duration) => $this->sleeps[] = $duration->totalMilliseconds);
    Queue::fake([AnalyzeCompanyJob::class]);
});

function page(string $body, string $title = 'Acme'): string
{
    return "<html><head><title>{$title}</title><meta name=\"description\" content=\"شركة أكمي لتطوير البرمجيات\">"
        ."<meta property=\"og:site_name\" content=\"أكمي سوفت\"></head><body>{$body}</body></html>";
}

function filler(): string
{
    return '<p>'.str_repeat('نحن شركة برمجيات سعودية متخصصة في تطوير المواقع والتطبيقات باستخدام Laravel و React. ', 8).'</p>';
}

/** Fake site: path => body | status code. */
function fakeSite(array $pages, string $robots = "User-agent: *\nDisallow: /private"): void
{
    Http::fake(function (Request $request) use ($pages, $robots) {
        $path = rawurldecode(parse_url($request->url(), PHP_URL_PATH) ?: '/');
        if ($path === '/robots.txt') {
            return Http::response($robots, 200, ['Content-Type' => 'text/plain']);
        }
        $page = $pages[$path] ?? 404;

        return is_int($page) ? Http::response('nope', $page) : Http::response($page, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    });
}

test('finds contact pages from homepage links, extracts contacts, respects robots and the page cap', function () {
    $company = Company::factory()->create(['name' => 'Acmesoft', 'domain' => 'acmesoft.sa', 'website' => 'https://acmesoft.sa']);

    fakeSite([
        '/' => page(filler().'<a href="/ar/تواصل-معنا">تواصل معنا</a> <a href="/careers">انضم إلينا</a> <a href="/private/x">Contact (private)</a>'
            .'<a href="https://other.com/contact">contact elsewhere</a><a href="https://sa.linkedin.com/company/acmesoft">in</a>'),
        '/ar/تواصل-معنا' => page('<p>الجوال: 0551234567</p><a href="https://wa.me/966501112233">واتساب</a><a href="mailto:info@acmesoft.sa">mail</a>'),
        '/careers' => page(filler().'<p>للتوظيف: hr@acmesoft.sa — 0544445555</p>'),
        '/about' => page(filler()),
    ]);

    (new EnrichCompanyJob($company->id))->handle(app(\App\Services\Enrichment\CompanyEnricher::class));

    $company->refresh();
    $contacts = $company->contacts()->get()->keyBy('value');

    expect($company->status)->toBe(CompanyStatus::Enriched)
        ->and($company->enriched_at)->not->toBeNull()
        ->and($company->name)->toBe('أكمي سوفت') // domain-derived name replaced by og:site_name
        ->and($company->linkedin_url)->toBe('https://www.linkedin.com/company/acmesoft')
        ->and($company->meta_description)->toContain('أكمي')
        ->and($company->website_text)->toContain('Laravel')
        ->and($contacts->keys()->all())->toEqualCanonicalizing(['+966551234567', '+966501112233', 'info@acmesoft.sa', 'hr@acmesoft.sa', '+966544445555'])
        ->and($contacts['+966501112233']->is_whatsapp)->toBeTrue()
        ->and($contacts['hr@acmesoft.sa']->label->value)->toBe('hr')
        ->and($contacts['+966544445555']->label->value)->toBe('hr');

    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/private'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'other.com'));
    Http::assertSent(fn (Request $r) => str_starts_with($r->header('User-Agent')[0] ?? '', 'SaudiDevDailyBot'));

    $pageRequests = collect(Http::recorded())->reject(fn ($pair) => str_ends_with($pair[0]->url(), 'robots.txt'));
    expect($pageRequests->count())->toBeLessThanOrEqual(6);

    // Politeness: paused between requests to the same host, never more than 3 s.
    expect($this->sleeps)->not->toBeEmpty()
        ->and(max($this->sleeps))->toBeLessThanOrEqual(3000);

    Queue::assertPushed(AnalyzeCompanyJob::class, fn ($job) => $job->companyId === $company->id);
});

test('falls back to common paths when the homepage has no matching links, max 6 pages', function () {
    $company = Company::factory()->create(['website' => 'https://plain.sa', 'domain' => 'plain.sa']);
    fakeSite(['/' => page(filler()), '/contact' => page('<p>هاتف: 0112345678</p>')]);

    app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

    $requested = collect(Http::recorded())->map(fn ($pair) => rawurldecode(parse_url($pair[0]->url(), PHP_URL_PATH) ?: '/'))
        ->reject(fn ($p) => $p === '/robots.txt')->values();

    expect($requested->all())->toContain('/', '/contact-us', '/contact')
        ->and($requested->count())->toBeLessThanOrEqual(6)
        ->and($company->contacts()->value('value'))->toBe('+966112345678');
});

test('JS-heavy pages are re-rendered with the headless browser', function () {
    config(['outreach.browsershot.enabled' => true]);
    app()->bind(JsRendererInterface::class, fn () => new class implements JsRendererInterface
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function render(string $url): string
        {
            return page(filler().'<p>Mobile: +966 55 999 8877</p>');
        }
    });

    $company = Company::factory()->create(['website' => 'https://spa.sa', 'domain' => 'spa.sa']);
    fakeSite(['/' => '<html><body><div id="root"></div><script src="/app.js"></script></body></html>']);

    app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

    expect($company->contacts()->value('value'))->toBe('+966559998877')
        ->and(collect($company->fresh()->enrichment['pages'])->pluck('status'))->toContain('rendered');
});

test('an unreachable or broken site never fails the job', function (string $failure) {
    $company = Company::factory()->create(['website' => 'https://down.sa', 'domain' => 'down.sa']);
    Http::fake(['*' => $failure === 'http-500'
        ? Http::response('x', 500)
        : fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection refused')]);

    (new EnrichCompanyJob($company->id))->handle(app(\App\Services\Enrichment\CompanyEnricher::class));

    $company->refresh();
    expect($company->status)->toBe(CompanyStatus::Enriched)
        ->and($company->enrichment['reachable'])->toBeFalse()
        ->and($company->enrichment['errors'][0])->toContain('homepage unreachable');
    Queue::assertPushed(AnalyzeCompanyJob::class); // still analyzed (may have contacts from OSM)
})->with(['http-500', 'connection-refused']);

test('a site with a broken TLS chain is skipped by default, and read only when explicitly allowed', function (bool $allow) {
    config(['outreach.crawler.allow_insecure_tls' => $allow]);
    $company = Company::factory()->create(['website' => 'https://badchain.sa', 'domain' => 'badchain.sa']);
    $verifiedCalls = 0;

    Http::fake(function (Request $request, array $options) use (&$verifiedCalls) {
        if (($options['verify'] ?? true) !== false) {
            $verifiedCalls++;
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 60: SSL certificate problem: unable to get local issuer certificate');
        }

        return str_ends_with($request->url(), 'robots.txt')
            ? Http::response('', 404)
            : Http::response(page(filler().'<p>0551234567</p>'), 200, ['Content-Type' => 'text/html']);
    });

    app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

    if ($allow) {
        expect($company->contacts()->value('value'))->toBe('+966551234567')
            ->and(collect($company->fresh()->enrichment['pages'])->pluck('status'))->toContain('ok (TLS not verified)');
    } else {
        expect($company->contacts()->count())->toBe(0)
            ->and($company->fresh()->enrichment['errors'][0])->toContain('cURL error 60');
    }
})->with(['default (off)' => false, 'opt-in' => true]);

test('a company without a website is marked enriched without any request', function () {
    $company = Company::factory()->create(['website' => null, 'domain' => null]);
    Http::fake();

    app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

    Http::assertNothingSent();
    expect($company->fresh()->status)->toBe(CompanyStatus::Enriched);
});

test('a phone already owned by another company is not duplicated or stolen', function () {
    $other = Company::factory()->create();
    $other->contacts()->create(['type' => 'phone', 'value' => '+966551234567', 'is_mobile' => true]);
    $company = Company::factory()->create(['website' => 'https://dup.sa', 'domain' => 'dup.sa']);
    fakeSite(['/' => page(filler().'<p>0551234567</p>')]);

    app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

    expect($company->contacts()->count())->toBe(0)
        ->and($other->contacts()->count())->toBe(1);
});

describe('RobotsTxt', function () {
    test('applies the right group with longest-match and wildcards', function () {
        $robots = RobotsTxt::parse(implode("\n", [
            'User-agent: Googlebot', 'Disallow: /',
            '', 'User-agent: *', 'Disallow: /admin', 'Disallow: /*.pdf$', 'Allow: /admin/public', 'Disallow: /search?',
        ]), 'SaudiDevDailyBot');

        expect($robots->allows('https://a.sa/'))->toBeTrue()
            ->and($robots->allows('https://a.sa/contact'))->toBeTrue()
            ->and($robots->allows('https://a.sa/admin/x'))->toBeFalse()
            ->and($robots->allows('https://a.sa/admin/public/page'))->toBeTrue()
            ->and($robots->allows('https://a.sa/files/cv.pdf'))->toBeFalse()
            ->and($robots->allows('https://a.sa/files/cv.pdf?x=1'))->toBeTrue()
            ->and($robots->allows('https://a.sa/search?q=1'))->toBeFalse();
    });

    test('a group for our bot overrides "*"', function () {
        $robots = RobotsTxt::parse("User-agent: *\nAllow: /\n\nUser-agent: SaudiDevDailyBot\nDisallow: /", 'SaudiDevDailyBot');

        expect($robots->allows('https://a.sa/contact'))->toBeFalse();
    });

    test('a site that disallows everything is not crawled', function () {
        $company = Company::factory()->create(['website' => 'https://closed.sa', 'domain' => 'closed.sa']);
        fakeSite(['/' => page(filler().'<p>0551234567</p>')], "User-agent: *\nDisallow: /");

        app(\App\Services\Enrichment\CompanyEnricher::class)->enrich($company);

        Http::assertNotSent(fn (Request $r) => ! str_ends_with($r->url(), 'robots.txt'));
        expect($company->contacts()->count())->toBe(0);
    });
});
