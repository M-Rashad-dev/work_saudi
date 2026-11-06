<?php

namespace App\Services\Enrichment;

use App\DTO\NormalizedPhone;
use libphonenumber\Leniency;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;

/**
 * Parses a raw phone string (any digits script, any formatting) into E.164 with region SA as default.
 *   "٠٥٥ ١٢٣ ٤٥٦٧" → +966551234567 (mobile)   "00966 11 234 5678" → +966112345678 (landline)
 *   "920012345" → +966920012345 (unified number, not mobile)
 */
class PhoneNormalizer
{
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '＋' => '+',
    ];

    private readonly PhoneNumberUtil $util;

    public function __construct()
    {
        $this->util = PhoneNumberUtil::getInstance();
    }

    public static function convertDigits(string $text): string
    {
        return strtr($text, self::DIGITS);
    }

    /**
     * Phone numbers in free text, via libphonenumber's matcher (splits adjacent numbers correctly).
     * Only numbers written with a real prefix (+, 00, 966, 0, 920, 800) are kept, so bare digit runs
     * (commercial registration, VAT, prices) are ignored.
     *
     * @return list<array{raw: string, start: int}> start = character offset in $text
     */
    public function findInText(string $text): array
    {
        $text = self::convertDigits($text);
        $found = [];

        foreach ($this->util->findNumbers($text, 'SA', Leniency::VALID(), 10000) as $match) {
            $raw = $match->rawString();
            $lead = ltrim($raw, " (\t");
            if (preg_match('/^(\+|00|966|0|920|800)/', $lead)) {
                $found[] = ['raw' => $raw, 'start' => $match->start()];
            }
        }

        return $found;
    }

    public function normalize(string $raw): ?NormalizedPhone
    {
        $value = self::convertDigits(rawurldecode($raw));
        $value = preg_replace('/^\s*(tel|callto|phone):/i', '', $value);
        // Drop extensions ("ext 12", "تحويلة 12") and keep digits + a leading "+".
        $value = preg_replace('/(ext\.?|x|تحويلة|داخلي)\s*\d+\s*$/iu', '', $value);
        $hasPlus = str_starts_with(ltrim($value), '+');
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '' || strlen($digits) < 8 || strlen($digits) > 15) {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            [$digits, $hasPlus] = [substr($digits, 2), true];
        } elseif (! $hasPlus && str_starts_with($digits, '966') && strlen($digits) >= 11) {
            $hasPlus = true; // "9665xxxxxxxx" written without "+" (wa.me style)
        } elseif (! $hasPlus && preg_match('/^5\d{8}$/', $digits)) {
            $digits = '0'.$digits; // "5xxxxxxxx" → national mobile
        }

        try {
            $number = $this->util->parse(($hasPlus ? '+' : '').$digits, 'SA');
        } catch (NumberParseException) {
            return null;
        }

        if (! $this->util->isValidNumber($number)) {
            return null;
        }

        $type = $this->util->getNumberType($number);

        return new NormalizedPhone(
            e164: $this->util->format($number, PhoneNumberFormat::E164),
            isMobile: in_array($type, [PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE], true),
            region: $this->util->getRegionCodeForNumber($number),
            display: $this->util->format($number, PhoneNumberFormat::INTERNATIONAL),
        );
    }
}
