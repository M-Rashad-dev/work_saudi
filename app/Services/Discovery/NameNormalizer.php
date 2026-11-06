<?php

namespace App\Services\Discovery;

/**
 * Builds `companies.normalized_name`: the secondary dedupe key (with city).
 * "شركةُ أكمي للتقنية المحدودة" and "شركه اكمي للتقنيه" → "اكمي للتقنيه".
 */
class NameNormalizer
{
    /** Generic words dropped from names (compared AFTER letter normalization). */
    private const STOP_WORDS = [
        // Arabic (normalized: ة→ه, أ/إ/آ→ا, ؤ→و)
        'شركه', 'موسسه', 'المحدوده', 'محدوده', 'ذ.م.م', 'ذمم', 'مساهمه', 'مقفله',
        // English
        'company', 'co', 'llc', 'ltd', 'limited', 'inc', 'est', 'establishment', 'corp', 'corporation',
        'plc', 'wll', 'fzco', 'fze', 'the',
    ];

    public function normalize(string $name): string
    {
        $original = $name;
        $name = $this->normalizeLetters($name);

        // Punctuation (Latin + Arabic) → space. Keep letters, digits and spaces.
        $name = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $name);

        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_values(array_filter($words, fn (string $w) => ! in_array($w, self::STOP_WORDS, true)));

        $result = implode(' ', $kept ?: $words);

        return $result !== '' ? mb_substr($result, 0, 191) : mb_strtolower(trim($original));
    }

    /** Lowercase, Arabic letter folding, diacritics/tatweel removed, Arabic-Indic digits → 0-9. */
    public function normalizeLetters(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text); // tashkeel + tatweel

        return strtr($text, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }

    /** Latin tokens of 3+ chars, used to match a company name against a domain. */
    public function latinTokens(string $name): array
    {
        preg_match_all('/[a-z0-9]{3,}/', mb_strtolower($name), $m);

        return array_values(array_diff($m[0], self::STOP_WORDS, ['tech', 'for', 'and', 'solutions', 'group']));
    }
}
