<?php

namespace App\Enums;

enum CompanySource: string
{
    use HasOptions;

    case Searxng = 'searxng';
    case Overpass = 'overpass';
    case Serper = 'serper';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Searxng => 'بحث (SearXNG)',
            self::Overpass => 'خرائط OpenStreetMap',
            self::Serper => 'Serper',
            self::Manual => 'يدوي',
        };
    }
}
