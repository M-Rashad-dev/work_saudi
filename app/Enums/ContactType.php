<?php

namespace App\Enums;

enum ContactType: string
{
    use HasOptions;

    case Phone = 'phone';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'هاتف',
            self::Email => 'إيميل',
        };
    }
}
