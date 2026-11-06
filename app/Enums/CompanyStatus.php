<?php

namespace App\Enums;

enum CompanyStatus: string
{
    use HasOptions;

    case New = 'new';
    case Enriched = 'enriched';
    case Qualified = 'qualified';
    case Rejected = 'rejected';
    case Shown = 'shown';

    public function label(): string
    {
        return match ($this) {
            self::New => 'جديدة',
            self::Enriched => 'تم جمع بياناتها',
            self::Qualified => 'مؤهلة',
            self::Rejected => 'مرفوضة',
            self::Shown => 'تم عرضها',
        };
    }
}
