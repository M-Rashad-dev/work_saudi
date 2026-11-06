<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** "+966551234567" → "055 123 4567" (Saudi numbers in national format, others international). */
final class PhoneFormatter
{
    public static function display(string $e164): string
    {
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($e164, 'SA');
        } catch (NumberParseException) {
            return $e164;
        }

        return $util->format($number, $util->getRegionCodeForNumber($number) === 'SA'
            ? PhoneNumberFormat::NATIONAL
            : PhoneNumberFormat::INTERNATIONAL);
    }
}
