<?php

namespace App\Services\Discovery;

/**
 * Finds a Saudi city (from config('outreach.cities')) mentioned in free text.
 */
class CityDetector
{
    /** @var array<string, string>|null alias regex => city key */
    private ?array $patterns = null;

    public function __construct(private readonly NameNormalizer $names) {}

    /** City key ("riyadh") of the first city mentioned, or null. */
    public function detect(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $text = $this->names->normalizeLetters($text);
        $best = null;
        $bestPos = PHP_INT_MAX;

        foreach ($this->patterns() as $regex => $key) {
            if (preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE) && $m[0][1] < $bestPos) {
                $best = $key;
                $bestPos = $m[0][1];
            }
        }

        return $best;
    }

    /** Arabic display name for a city key ("riyadh" → "الرياض"). */
    public function arabicName(?string $key): ?string
    {
        return $key === null ? null : (config("outreach.cities.{$key}.ar") ?? null);
    }

    /** @return array<string, string> */
    private function patterns(): array
    {
        if ($this->patterns !== null) {
            return $this->patterns;
        }

        $this->patterns = [];
        foreach (config('outreach.cities') as $key => $city) {
            foreach ([$city['ar'], $city['en'], ...($city['aliases'] ?? [])] as $alias) {
                $alias = preg_quote($this->names->normalizeLetters($alias), '/');
                // Not inside another word; allow Arabic prefixes و/ب/ل ("بالرياض"). Stops "الخبر" matching "الخبره".
                $this->patterns['/(?<!\p{L})(?:[وبل])?'.$alias.'(?!\p{L})/u'] = $key;
            }
        }

        return $this->patterns;
    }
}
