<?php

namespace App\Services\Analysis;

use App\DTO\CompanyAnalysisResult;
use App\Exceptions\InvalidLlmOutputException;

/**
 * Strict validation of the model's JSON. Tolerates markdown fences and surrounding prose
 * (models do that), but every required key must be present with the right type.
 */
class AnalysisValidator
{
    private const REQUIRED = ['is_software_company', 'is_hiring_devs', 'relevance', 'description_ar', 'reason_ar'];

    /** @throws InvalidLlmOutputException */
    public function parse(string $raw): CompanyAnalysisResult
    {
        $data = $this->decode($raw);

        foreach (self::REQUIRED as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidLlmOutputException("missing key \"{$key}\"");
            }
        }

        return new CompanyAnalysisResult(
            isSoftwareCompany: $this->bool($data, 'is_software_company'),
            isHiringDevs: $this->bool($data, 'is_hiring_devs'),
            relevance: $this->relevance($data['relevance']),
            descriptionAr: $this->arabicText($data, 'description_ar', 800),
            reasonAr: $this->arabicText($data, 'reason_ar', 200),
            services: $this->strings($data['services'] ?? [], 'services', 10),
            techHints: $this->strings($data['tech_hints'] ?? [], 'tech_hints', 15),
            city: $this->city($data['city'] ?? null),
        );
    }

    private function decode(string $raw): array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $data = json_decode($text, true);
        if (! is_array($data)) {
            // Prose around the object: take the outermost {...}.
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            $data = ($start !== false && $end > $start) ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidLlmOutputException('not a JSON object');
        }

        return $data;
    }

    private function bool(array $data, string $key): bool
    {
        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return in_array($value, [1, '1', 'true'], true);
        }

        throw new InvalidLlmOutputException("\"{$key}\" must be boolean");
    }

    private function relevance(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new InvalidLlmOutputException('"relevance" must be a number 0-100');
        }
        $value = (float) $value;
        if ($value > 0 && $value < 1) {
            $value *= 100; // some models answer 0.85 instead of 85
        }

        return (int) round(min(100, max(0, $value)));
    }

    private function arabicText(array $data, string $key, int $max): string
    {
        $value = $data[$key];
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidLlmOutputException("\"{$key}\" must be a non-empty string");
        }
        if (! preg_match('/\p{Arabic}/u', $value)) {
            throw new InvalidLlmOutputException("\"{$key}\" must be in Arabic");
        }

        return mb_substr(trim($value), 0, $max);
    }

    /** @return list<string> */
    private function strings(mixed $value, string $key, int $max): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*[,،]\s*/u', $value) ?: [];
        }
        if (! is_array($value)) {
            throw new InvalidLlmOutputException("\"{$key}\" must be an array of strings");
        }

        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && ($item = trim($item)) !== '' && mb_strlen($item) <= 60) {
                $clean[mb_strtolower($item)] = $item;
            }
        }

        return array_slice(array_values($clean), 0, $max);
    }

    private function city(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' || in_array(mb_strtolower($value), ['null', 'unknown', 'غير معروف'], true) ? null : mb_substr($value, 0, 100);
    }
}
