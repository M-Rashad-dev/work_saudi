<?php

use App\DTO\SearchResultDTO;
use App\Services\Discovery\CompanyTitleParser;
use App\Services\Discovery\JobBoardCompanyExtractor;

function result(string $title, string $snippet = '', string $url = 'https://www.linkedin.com/jobs/view/1'): SearchResultDTO
{
    return new SearchResultDTO(url: $url, title: $title, snippet: $snippet, domain: parse_url($url, PHP_URL_HOST));
}

describe('JobBoardCompanyExtractor', function () {
    test('extracts the hiring company from job-board titles and snippets', function (string $title, string $snippet, ?string $expected) {
        expect(app(JobBoardCompanyExtractor::class)->extract(result($title, $snippet)))->toBe($expected);
    })->with([
        'LinkedIn hiring' => ['Acme Tech hiring Laravel Developer in Riyadh, Riyadh, Saudi Arabia | LinkedIn', '', 'Acme Tech'],
        'LinkedIn is hiring' => ['Nova Systems is hiring: Senior PHP Developer | LinkedIn', '', 'Nova Systems'],
        'Bayt at' => ['PHP Developer at Elm Information Security - Bayt.com', '', 'Elm Information Security'],
        'Indeed segments' => ['Backend Developer - Tamkeen Soft - Riyadh | Indeed.com', '', 'Tamkeen Soft'],
        'Arabic تعلن' => ['وظائف مطورين', 'تعلن شركة أكمي للتقنية عن توفر وظيفة مطور ويب في الرياض', 'شركة أكمي للتقنية'],
        'Arabic شركة X توظف' => ['شركة رؤية الرقمية توظف مطور React بالرياض', '', 'شركة رؤية الرقمية'],
        'Arabic لدى' => ['مطلوب مطور Laravel لدى مؤسسة الحلول الذكية في جدة', '', 'مؤسسة الحلول الذكية'],
        'Company: field' => ['Full Stack Developer job', 'Company: Bright Code. Location: Dammam. Salary: ...', 'Bright Code'],
        'Arabic الشركة:' => ['وظيفة مبرمج', 'الشركة: حلول البيانات، المدينة: الرياض', 'حلول البيانات'],
        'trailing location' => ['Laravel Developer at Acme Tech in Riyadh - Bayt.com', '', 'Acme Tech'],
    ]);

    test('returns null when there is no real company name', function (string $title, string $snippet) {
        expect(app(JobBoardCompanyExtractor::class)->extract(result($title, $snippet)))->toBeNull();
    })->with([
        'generic listing' => ['500+ Laravel Developer jobs in Saudi Arabia (12 new)', 'Today’s top Laravel Developer jobs in Saudi Arabia.'],
        'confidential' => ['PHP Developer at Confidential - Bayt.com', ''],
        'only a city' => ['Backend Developer - Riyadh - Saudi Arabia | Indeed.com', ''],
        'leading company' => ['مطلوب مطور ويب لدى شركة رائدة', ''],
        'arabic generic' => ['وظائف مطور ويب في السعودية', 'أحدث وظائف مطور ويب في السعودية اليوم'],
    ]);
});

describe('CompanyTitleParser', function () {
    test('picks the company segment from a homepage title', function (string $title, string $url, string $domain, string $expected) {
        $result = new SearchResultDTO(url: $url, title: $title, snippet: '', domain: $domain);
        expect(app(CompanyTitleParser::class)->parse($result, $domain))->toBe($expected);
    })->with([
        ['Acme Soft | Web & Mobile Development in Riyadh', 'https://acmesoft.com/', 'acmesoft.com', 'Acme Soft'],
        ['Home - Nova Systems', 'https://www.novasystems.sa/', 'novasystems.sa', 'Nova Systems'],
        ['الرئيسية - شركة إبداع للبرمجيات', 'https://ibdaa.com.sa/', 'ibdaa.com.sa', 'شركة إبداع للبرمجيات'],
        ['أفضل 10 شركات برمجة في الرياض 2026', 'https://blog-tech.com/top-10', 'blog-tech.com', 'Blog Tech'],
        ['Top Software Companies in Jeddah', 'https://some-agency.com/blog/x', 'some-agency.com', 'Some Agency'],
    ]);
});
