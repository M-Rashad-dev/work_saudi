<?php

namespace App\Enums;

enum QueryGroup: string
{
    use HasOptions;

    case Discovery = 'discovery';
    case Hiring = 'hiring';

    public function label(): string
    {
        return match ($this) {
            self::Discovery => 'اكتشاف شركات',
            self::Hiring => 'إشارات توظيف',
        };
    }
}
