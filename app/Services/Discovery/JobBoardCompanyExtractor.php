<?php

namespace App\Services\Discovery;

use App\DTO\SearchResultDTO;

/**
 * Pulls the hiring company's name out of a job-board search result (LinkedIn, Bayt, Indeed, Glassdoor…).
 *
 *   "Acme Tech hiring Laravel Developer in Riyadh | LinkedIn"      → "Acme Tech"
 *   "PHP Developer at Acme Tech - Bayt.com"                        → "Acme Tech"
 *   "Backend Developer - Acme Tech - Riyadh | Indeed.com"          → "Acme Tech"
 *   "تعلن شركة أكمي للتقنية عن توفر وظيفة مطور ويب"                  → "شركة أكمي للتقنية"
 */
class JobBoardCompanyExtractor
{
    private const SEP = '[|\-–—·•»]';

    /** Title/snippet patterns, tried in order. Each must capture (?<c>…). */
    private const PATTERNS = [
        // "Acme hiring …" / "Acme is hiring …"
        '/^(?<c>[^|\-–—·•»:]{2,60}?)\s+(?:is\s+)?hiring\b/iu',
        // "… at Acme - Bayt" / "… @ Acme"
        '/\s(?:at|@)\s+(?<c>[^|\-–—·•»,()]{2,60}?)(?:\s*[|\-–—·•»,(]|\s+in\s|\s+jobs?\b|$)/iu',
        // "Company: Acme" / "Employer: Acme"
        '/\b(?:company|employer|hiring company|organization)\s*[:：]\s*(?<c>[^|\-–—·•»,\n.]{2,60})/iu',
        // "الشركة: أكمي" / "جهة العمل: أكمي"
        '/(?:اسم الشركة|الشركة|جهة العمل|صاحب العمل)\s*[:：]\s*(?<c>[^|\-–—·•»،,\n.]{2,60})/u',
        // "تعلن شركة أكمي عن …"
        '/(?:تعلن|أعلنت|اعلنت|يعلن)\s+(?<c>(?:شركة|مؤسسة|مجموعة)\s+[^|\-–—·•»،,\n.]{2,50}?)\s+(?:عن|توفر|حاجتها)/u',
        // "شركة أكمي تعلن/توظف/تبحث …"
        '/(?<c>(?:شركة|مؤسسة|مجموعة)\s+[^|\-–—·•»،,\n.]{2,50}?)\s+(?:تعلن|توظف|تبحث|تطلب|بحاجة|ترغب)/u',
        // "مطلوب مطور لدى أكمي" / "… بشركة أكمي"
        '/(?:لدى|لدي|بشركة|بمؤسسة)\s+(?<c>[^|\-–—·•»،,\n.]{2,50}?)(?:\s+(?:في|ب|بال)\S*|\s*[|\-–—·•»،,]|$)/u',
        // "Acme توظف …" (LinkedIn Arabic UI)
        '/^(?<c>[^|\-–—·•»:]{2,60}?)\s+(?:توظف|يوظف)\s/u',
    ];

    /** Words that are never a company name on their own. */
    private const REJECT = [
        'linkedin', 'indeed', 'bayt', 'bayt.com', 'glassdoor', 'naukrigulf', 'gulftalent', 'wuzzuf', 'tanqeeb',
        'jobs', 'job', 'careers', 'vacancies', 'hiring', 'saudi arabia', 'ksa', 'confidential', 'company',
        'a company', 'leading company', 'our client', 'client', 'top company', 'private company',
        'وظائف', 'وظيفة', 'السعودية', 'المملكة العربية السعودية', 'شركة', 'شركة سرية', 'شركة رائدة',
        'شركة كبرى', 'شركة كبيرة', 'جهة سرية', 'عميلنا', 'القطاع الخاص',
    ];

    /** A candidate containing one of these is a job title, not a company. */
    private const JOB_WORDS = [
        'developer', 'engineer', 'programmer', 'designer', 'manager', 'specialist', 'stack', 'backend', 'frontend',
        'intern', 'remote', 'وظيفة', 'وظائف', 'مطور', 'مبرمج', 'مهندس', 'مصمم', 'مطلوب', 'دوام', 'براتب',
    ];

    public function __construct(private readonly CityDetector $cities) {}

    public function extract(SearchResultDTO $result): ?string
    {
        foreach ([$result->title, $result->snippet] as $text) {
            $text = $this->clean($text);
            if ($text === '') {
                continue;
            }

            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $text, $m) && ($name = $this->validate($m['c'])) !== null) {
                    return $name;
                }
            }
        }

        return $this->fromSegments($this->clean($result->title));
    }

    /**
     * Indeed/LinkedIn style "Job Title - Company - City | Board": the segment after the job title.
     */
    private function fromSegments(string $title): ?string
    {
        $segments = array_values(array_filter(array_map('trim', preg_split('/\s+'.self::SEP.'\s+/u', $title) ?: [])));
        if (count($segments) < 3 || ! $this->looksLikeJobTitle($segments[0])) {
            return null;
        }

        foreach (array_slice($segments, 1) as $segment) {
            if (($name = $this->validate($segment)) !== null) {
                return $name;
            }
        }

        return null;
    }

    private function validate(string $candidate): ?string
    {
        // Unicode-safe trim (PHP trim() is byte-based and would cut Arabic letters).
        $candidate = preg_replace('/^[\s\p{P}\p{S}]+|[\s\p{P}\p{S}]+$/u', '', $candidate) ?? '';
        // Drop trailing location: "Acme Tech in Riyadh", "Acme Tech, Riyadh".
        $candidate = trim(preg_replace('/\s+(?:in|في)\s+.+$/u', '', $candidate));
        $length = mb_strlen($candidate);

        if ($length < 2 || $length > 60) {
            return null;
        }

        $lower = mb_strtolower($candidate);
        if (in_array($lower, self::REJECT, true) || $this->cities->detect($candidate) !== null && $this->isOnlyCity($candidate)) {
            return null;
        }

        foreach (self::JOB_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return null;
            }
        }

        // Must contain at least one letter, and not be a domain of a board.
        if (! preg_match('/\p{L}/u', $candidate) || preg_match('/\.(com|net|sa|org)$/i', $candidate)) {
            return null;
        }

        return $candidate;
    }

    private function isOnlyCity(string $candidate): bool
    {
        // "Riyadh", "Riyadh, Saudi Arabia", "الرياض" — nothing besides the city and country.
        $rest = preg_replace('/saudi arabia|ksa|السعودية|المملكة|,|،/iu', '', $candidate);

        return mb_strlen(trim($rest)) <= 20 && str_word_count(trim($rest)) <= 2;
    }

    private function looksLikeJobTitle(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::JOB_WORDS as $word) {
            if (str_contains($lower, $word)) {
                return true;
            }
        }

        return false;
    }

    private function clean(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5)));
    }
}
