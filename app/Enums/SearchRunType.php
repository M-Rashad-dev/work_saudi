<?php

namespace App\Enums;

enum SearchRunType: string
{
    use HasOptions;

    case Discover = 'discover';
    case Overpass = 'overpass';
    case Serper = 'serper';
    case BuildBatch = 'build_batch';

    public function label(): string
    {
        return match ($this) {
            self::Discover => 'بحث يومي',
            self::Overpass => 'OpenStreetMap',
            self::Serper => 'Serper',
            self::BuildBatch => 'تجهيز دفعة',
        };
    }
}
