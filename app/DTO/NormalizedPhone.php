<?php

namespace App\DTO;

final readonly class NormalizedPhone
{
    public function __construct(
        public string $e164,
        public bool $isMobile,
        public ?string $region,
        public string $display,
    ) {}
}
