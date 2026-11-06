<?php

use App\DTO\CompanyAnalysisInput;
use App\Enums\CompanyStatus;
use App\Enums\SettingKey;
use App\Exceptions\InvalidLlmOutputException;
use App\Jobs\AnalyzeCompanyJob;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Analysis\AnalysisValidator;
use App\Services\Analysis\CompanyAnalyzer;
use App\Services\Analysis\Drivers\GeminiDriver;
use App\Services\Analysis\Drivers\HeuristicDriver;
use App\Services\Analysis\LlmThrottle;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

const GEMINI = 'generativelanguage.googleapis.com/*';

function validJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'is_software_company' => true,
        'is_hiring_devs' => true,
        'relevance' => 88,
        'description_ar' => 'شركة سعودية متخصصة في تطوير المواقع والتطبيقات للشركات. تقدم حلول SaaS مبنية على Laravel و React.',
        'services' => ['تطوير المواقع', 'تطوير التطبيقات'],
        'tech_hints' => ['Laravel', 'React'],
        'city' => 'الرياض',
        'reason_ar' => 'بتوظف مطور Laravel',
    ], $overrides), JSON_UNESCAPED_UNICODE);
}

function geminiReply(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]], 'finishReason' => 'STOP']]];
}

function analyzable(array $attributes = []): Company
{
    $company = Company::factory()->create(array_merge([
        'status' => CompanyStatus::Enriched,
        'website_text' => 'نحن شركة برمجيات في الرياض. نطور تطبيقات ويب باستخدام Laravel و React. مطلوب مطور Laravel للانضمام لفريقنا.',
        'city' => null,
    ], $attributes));
    Contact::factory()->mobile()->create(['company_id' => $company->id]);

    return $company;
}

function runAnalysis(Company $company): void
{
    (new AnalyzeCompanyJob($company->id))->handle(
        app(CompanyAnalyzer::class), app(\App\Services\Analysis\CompanyQualifier::class), app(\App\Services\Analysis\AnalysisBacklog::class),
    );
}

beforeEach(function () {
    Sleep::fake();
    config(['outreach.llm.gemini.api_key' => 'test-key', 'outreach.llm.gemini.model' => 'gemini-test']);
});

describe('AnalysisValidator', function () {
    test('accepts strict JSON, markdown fences and surrounding prose', function (string $raw) {
        $result = app(AnalysisValidator::class)->parse($raw);

        expect($result->relevance)->toBe(88)
            ->and($result->isHiringDevs)->toBeTrue()
            ->and($result->techHints)->toBe(['Laravel', 'React']);
    })->with([
        'plain' => [validJson()],
        'fenced' => ["```json\n".validJson()."\n```"],
        'prose' => ['Here is the analysis: '.validJson().' Hope it helps.'],
    ]);

    test('normalizes loose but recoverable values', function () {
        $result = app(AnalysisValidator::class)->parse(validJson([
            'relevance' => '0.75', 'is_hiring_devs' => 'false', 'services' => 'تطوير المواقع، استضافة', 'city' => 'null',
        ]));

        expect($result->relevance)->toBe(75)
            ->and($result->isHiringDevs)->toBeFalse()
            ->and($result->services)->toBe(['تطوير المواقع', 'استضافة'])
            ->and($result->city)->toBeNull();
    });

    test('rejects invalid output', function (string $raw, string $error) {
        expect(fn () => app(AnalysisValidator::class)->parse($raw))->toThrow(InvalidLlmOutputException::class, $error);
    })->with([
        'not json' => ['Sorry, I cannot help with that.', 'not a JSON object'],
        'array' => ['[1,2,3]', 'not a JSON object'],
        'missing key' => [json_encode(['is_software_company' => true]), 'missing key'],
        'english description' => [validJson(['description_ar' => 'A software company in Riyadh.']), 'must be in Arabic'],
        'bad boolean' => [validJson(['is_software_company' => 'maybe']), 'must be boolean'],
        'bad relevance' => [validJson(['relevance' => 'high']), 'relevance'],
    ]);
});

describe('drivers and fallback', function () {
    test('Gemini result is applied and the company qualifies', function () {
        Http::fake([GEMINI => Http::response(geminiReply(validJson()))]);
        $company = analyzable();

        runAnalysis($company);

        $company->refresh();
        expect($company->llm_driver)->toBe('gemini')
            ->and($company->status)->toBe(CompanyStatus::Qualified)
            ->and($company->relevance)->toBe(88)
            ->and($company->reason_ar)->toBe('بتوظف مطور Laravel')
            ->and($company->tech_hints)->toBe(['Laravel', 'React'])
            ->and($company->city)->toBe('الرياض')
            ->and($company->analyzed_at)->not->toBeNull();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'models/gemini-test:generateContent')
            && $r->hasHeader('x-goog-api-key', 'test-key')
            && $r['generationConfig']['responseMimeType'] === 'application/json'
            && str_contains($r['contents'][0]['parts'][0]['text'], 'Laravel'));
    });

    test('invalid output is retried once, then the same driver succeeds', function () {
        Http::fakeSequence(GEMINI)
            ->push(geminiReply('not json at all'))
            ->push(geminiReply(validJson()));

        $result = app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));

        expect($result->driver)->toBe('gemini');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r['contents'][0]['parts'][0]['text'], 'previous reply was invalid'));
    });

    test('invalid output twice falls back to the next driver (Ollama)', function () {
        config(['outreach.llm.ollama.url' => 'http://ollama.test', 'outreach.llm.ollama.model' => 'qwen2.5:3b']);
        Http::fake([
            GEMINI => Http::response(geminiReply('{"oops": true}')),
            'ollama.test/*' => Http::response(['message' => ['role' => 'assistant', 'content' => validJson(['relevance' => 70])]]),
        ]);

        $result = app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));

        expect($result->driver)->toBe('ollama')->and($result->relevance)->toBe(70);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'ollama.test/api/chat') && $r['format'] === 'json' && $r['stream'] === false);
    });

    test('429 is retried with backoff, then Gemini cools down and heuristic takes over', function () {
        Http::fake([GEMINI => Http::response(['error' => ['message' => 'Resource exhausted']], 429)]);

        $first = app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));

        expect($first->driver)->toBe('heuristic');
        Http::assertSentCount(3); // 1 + 2 retries
        expect(cache()->has(GeminiDriver::COOLDOWN_KEY))->toBeTrue()
            ->and(app(GeminiDriver::class)->isAvailable())->toBeFalse();

        // While cooling down, Gemini is not called at all.
        app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));
        Http::assertSentCount(3);
    });

    test('a suspended/invalid key pauses Gemini for an hour instead of failing on every company', function () {
        Http::fake([GEMINI => Http::response(['error' => ['code' => 403, 'message' => "Permission denied: Consumer 'projects/1' has been suspended."]], 403)]);

        expect(app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()))->driver)->toBe('heuristic');
        Http::assertSentCount(1); // 403 is not retried

        app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));
        app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));
        Http::assertSentCount(1); // paused: no more calls

        expect(cache(GeminiDriver::COOLDOWN_KEY))->toContain('suspended');

        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('banners', fn ($b) => collect($b)->contains(fn ($x) => str_contains($x['message'], 'Gemini متوقف'))));
    });

    test('drivers follow the order saved in settings', function () {
        app(SettingsRepository::class)->set(SettingKey::LlmDrivers, ['heuristic', 'gemini']);
        Http::fake();

        $result = app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()));

        expect($result->driver)->toBe('heuristic');
        Http::assertNothingSent();
    });

    test('Gemini without a key is skipped (no HTTP)', function () {
        config(['outreach.llm.gemini.api_key' => null]);
        Http::fake();

        expect(app(CompanyAnalyzer::class)->analyze(CompanyAnalysisInput::fromCompany(analyzable()))->driver)->toBe('heuristic');
        Http::assertNothingSent();
    });

    test('the throttle waits when the per-minute limit is reached', function () {
        Sleep::fake(syncWithCarbon: true);
        $throttle = app(LlmThrottle::class);

        $throttle->await('test', 2);
        $throttle->await('test', 2);
        Sleep::assertNeverSlept();

        $throttle->await('test', 2);
        Sleep::assertSleptTimes(1);
    });
});

describe('qualification', function () {
    test('companies without any contact are rejected without calling the LLM', function () {
        Http::fake();
        $company = Company::factory()->create(['status' => CompanyStatus::Enriched]);

        runAnalysis($company);

        Http::assertNothingSent();
        expect($company->fresh()->status)->toBe(CompanyStatus::Rejected)
            ->and($company->fresh()->reason_ar)->toBe('لا توجد وسيلة تواصل');
    });

    test('low relevance or non-software is rejected', function (array $json) {
        Http::fake([GEMINI => Http::response(geminiReply(validJson($json)))]);
        $company = analyzable();

        runAnalysis($company);

        expect($company->fresh()->status)->toBe(CompanyStatus::Rejected);
    })->with([
        'below min_relevance' => [['relevance' => 30]],
        'not software, not hiring' => [['is_software_company' => false, 'is_hiring_devs' => false, 'relevance' => 90]],
    ]);

    test('analysis stops once 30 qualified, unshown companies are waiting', function () {
        Http::fake();
        Company::factory()->count(30)->qualified()->create();
        $company = analyzable();

        runAnalysis($company);

        Http::assertNothingSent();
        expect($company->fresh()->status)->toBe(CompanyStatus::Enriched)
            ->and($company->fresh()->analyzed_at)->toBeNull();
    });

    test('a shown company is never re-qualified', function () {
        Http::fake();
        $company = analyzable(['status' => CompanyStatus::Shown]);

        runAnalysis($company);

        expect($company->fresh()->status)->toBe(CompanyStatus::Shown);
    });
});

describe('HeuristicDriver', function () {
    test('scores a Laravel company that is hiring', function () {
        $result = app(HeuristicDriver::class)->analyze(new CompanyAnalysisInput(
            name: 'Acme',
            jobPostUrl: 'https://linkedin.com/jobs/1',
            metaDescription: 'Acme builds software',
            websiteText: "شركة أكمي من أفضل شركات تطوير البرمجيات وتطوير المواقع والتطبيقات في الرياض، نعمل مع عملائنا منذ 2015.\nWe use Laravel, PHP and React. We're hiring a Laravel developer.",
        ));

        expect($result->isSoftwareCompany)->toBeTrue()
            ->and($result->isHiringDevs)->toBeTrue()
            ->and($result->relevance)->toBeGreaterThanOrEqual(70)
            ->and($result->techHints)->toContain('Laravel', 'PHP', 'React')
            ->and($result->reasonAr)->toBe('بتوظف مطور Laravel')
            ->and($result->descriptionAr)->toStartWith('شركة أكمي')
            ->and($result->city)->toBe('الرياض');
    });

    test('a non-tech business scores low and is not software', function () {
        $result = app(HeuristicDriver::class)->analyze(new CompanyAnalysisInput(
            name: 'Sweet Bakery', websiteText: 'Fresh bread and cakes every morning. Enterprise catering for capital events.',
        ));

        expect($result->isSoftwareCompany)->toBeFalse()
            ->and($result->relevance)->toBeLessThan(30)
            ->and($result->techHints)->toBe([]); // "enterprise" ≠ ERP, "capital" ≠ API
    });
});
