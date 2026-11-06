<?php

namespace App\Enums;

enum ContactLabel: string
{
    use HasOptions;

    case General = 'general';
    case Hr = 'hr';
    case Careers = 'careers';
    case Whatsapp = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::General => 'عام',
            self::Hr => 'الموارد البشرية',
            self::Careers => 'التوظيف',
            self::Whatsapp => 'واتساب',
        };
    }
}
