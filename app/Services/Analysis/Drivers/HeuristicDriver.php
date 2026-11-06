<?php

namespace App\Services\Analysis\Drivers;

use App\Contracts\LlmClientInterface;
use App\DTO\CompanyAnalysisInput;
use App\DTO\CompanyAnalysisResult;
use App\Services\Discovery\CityDetector;

/**
 * No-AI fallback: keyword scoring + the best Arabic sentence from the site. Always available.
 */
class HeuristicDriver implements LlmClientInterface
{
    /** term => weight */
    private const SOFTWARE = [
        'software' => 10, 'برمجيات' => 10, 'برمجة' => 8, 'تطوير البرمجيات' => 10, 'software development' => 10,
        'web development' => 9, 'تطوير المواقع' => 9, 'تطوير مواقع' => 9, 'تصميم مواقع' => 6, 'mobile app' => 8,
        'تطبيقات' => 7, 'تطوير تطبيقات' => 9, 'app development' => 9, 'saas' => 9, 'erp' => 6, 'crm' => 5,
        'it solutions' => 6, 'حلول تقنية' => 6, 'تقنية المعلومات' => 5, 'digital transformation' => 5,
        'التحول الرقمي' => 5, 'e-commerce' => 5, 'متاجر إلكترونية' => 5, 'المتاجر الالكترونية' => 5, 'ui/ux' => 5,
        'cloud' => 3, 'api' => 3, 'platform' => 3, 'منصة' => 3, 'أنظمة' => 3, 'systems' => 3, 'developers' => 4, 'مطورين' => 4,
    ];

    private const TECH = [
        'Laravel' => 12, 'PHP' => 10, 'React' => 10, 'Vue' => 6, 'Angular' => 5, 'Node.js' => 5, 'Next.js' => 6,
        'JavaScript' => 4, 'TypeScript' => 4, 'Python' => 3, 'Django' => 3, 'Flutter' => 4, 'React Native' => 6,
        '.NET' => 3, 'Java' => 2, 'Kotlin' => 2, 'Swift' => 2, 'WordPress' => 3, 'MySQL' => 3, 'AWS' => 2, 'Docker' => 2,
    ];

    private const HIRING = [
        'hiring', "we're hiring", 'join our team', 'open positions', 'vacancies', 'vacancy', 'job opening',
        'مطلوب', 'وظيفة', 'وظائف', 'انضم إلى فريقنا', 'انضم لفريقنا', 'نوظف', 'فرص عمل', 'فرص وظيفية', 'توظيف',
    ];

    private const DEV_ROLE = '/(developer|engineer|programmer|مطور|مبرمج|مهندس برمجيات|full ?stack|back ?end|front ?end)/iu';

    private const SERVICES = [
        'تطوير المواقع' => ['web development', 'تطوير المواقع', 'تطوير مواقع', 'تصميم مواقع', 'website'],
        'تطوير التطبيقات' => ['mobile app', 'app development', 'تطبيقات', 'ios', 'android'],
        'المتاجر الإلكترونية' => ['e-commerce', 'ecommerce', 'متاجر', 'متجر إلكتروني'],
        'أنظمة ERP' => ['erp', 'تخطيط موارد'],
        'حلول سحابية' => ['cloud', 'سحابي', 'سحابية'],
        'تصميم UI/UX' => ['ui/ux', 'user experience', 'تجربة المستخدم'],
        'التسويق الرقمي' => ['digital marketing', 'تسويق رقمي', 'التسويق الإلكتروني', 'seo'],
        'الأمن السيبراني' => ['cyber', 'سيبراني', 'security'],
    ];

    public function __construct(private readonly CityDetector $cities) {}

    public function name(): string
    {
        return 'heuristic';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function analyze(CompanyAnalysisInput $input): CompanyAnalysisResult
    {
        $text = $input->allText();

        $softwareScore = 0;
        $softwareHits = 0;
        foreach (self::SOFTWARE as $term => $weight) {
            if ($this->mentions($text, $term)) {
                $softwareScore += $weight;
                $softwareHits++;
            }
        }

        $tech = [];
        $techScore = 0;
        foreach (self::TECH as $name => $weight) {
            if ($this->mentions($text, mb_strtolower($name))) {
                $tech[] = $name;
                $techScore += $weight;
            }
        }

        $hiringWords = collect(self::HIRING)->contains(fn ($w) => $this->mentions($text, $w));
        $isHiring = $input->jobPostUrl !== null || ($hiringWords && preg_match(self::DEV_ROLE, $text) === 1);
        $isSoftware = $softwareHits >= 2 || count($tech) >= 2;

        $relevance = min(50, $softwareScore) + min(30, $techScore) + ($isHiring ? 20 : 0);
        if (! $isSoftware && ! $isHiring) {
            $relevance = min($relevance, 30);
        }

        return new CompanyAnalysisResult(
            isSoftwareCompany: $isSoftware,
            isHiringDevs: $isHiring,
            relevance: min(100, $relevance),
            descriptionAr: $this->description($input),
            reasonAr: $this->reason($isSoftware, $isHiring, $tech),
            services: $this->services($text),
            techHints: $tech,
            city: $this->cities->arabicName($this->cities->detect($text)),
        );
    }

    /**
     * Latin terms match as whole words ("erp" ≠ "enterprise", "api" ≠ "capital");
     * Arabic terms match as substrings (prefixes like "ال", "و", "ب" are glued to words).
     */
    private function mentions(string $text, string $term): bool
    {
        if (preg_match('/\p{Arabic}/u', $term)) {
            return str_contains($text, $term);
        }

        return preg_match('/(?<![\p{L}\d])'.preg_quote($term, '/').'(?![\p{L}\d])/u', $text) === 1;
    }

    private function reason(bool $isSoftware, bool $isHiring, array $tech): string
    {
        $stack = array_values(array_intersect($tech, ['Laravel', 'PHP', 'React']));

        return match (true) {
            $isHiring && $stack !== [] => 'بتوظف مطور '.$stack[0],
            $isHiring => 'بتوظف مطورين',
            $isSoftware && $stack !== [] => 'شركة برمجيات تستخدم '.implode(' و', $stack),
            $isSoftware => 'شركة برمجيات',
            default => 'شركة تقنية محتملة',
        };
    }

    /** Arabic meta description, else the first meaningful Arabic paragraph, else a template. */
    private function description(CompanyAnalysisInput $input): string
    {
        $candidates = array_filter([
            $input->metaDescription,
            ...preg_split('/\n+/u', $input->websiteText) ?: [],
            ...preg_split('/\n+/u', (string) $input->searchSnippet) ?: [],
        ]);

        foreach ($candidates as $paragraph) {
            $paragraph = trim(preg_replace('/\s+/u', ' ', $paragraph));
            $arabicLetters = preg_match_all('/\p{Arabic}/u', $paragraph);
            if (mb_strlen($paragraph) >= 60 && $arabicLetters >= mb_strlen($paragraph) * 0.4) {
                return $this->limitSentences($paragraph);
            }
        }

        $city = $input->city ? " في {$input->city}" : '';

        return "{$input->name} شركة{$city} تظهر في نتائج البحث عن شركات البرمجيات والتقنية. (وصف تلقائي بدون ذكاء اصطناعي)";
    }

    private function limitSentences(string $text): string
    {
        $sentences = preg_split('/(?<=[.!؟?])\s+/u', $text) ?: [$text];
        $out = '';
        foreach (array_slice($sentences, 0, 3) as $sentence) {
            if (mb_strlen($out.$sentence) > 320) {
                break;
            }
            $out .= ($out === '' ? '' : ' ').$sentence;
        }

        return $out !== '' ? $out : mb_substr($text, 0, 300).'…';
    }

    /** @return list<string> */
    private function services(string $text): array
    {
        $found = [];
        foreach (self::SERVICES as $label => $terms) {
            foreach ($terms as $term) {
                if ($this->mentions($text, $term)) {
                    $found[] = $label;

                    break;
                }
            }
        }

        return $found;
    }
}
