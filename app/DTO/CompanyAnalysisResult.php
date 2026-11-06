<?php

namespace App\DTO;

final readonly class CompanyAnalysisResult
{
    /**
     * @param  list<string>  $services
     * @param  list<string>  $techHints
     */
    public function __construct(
        public bool $isSoftwareCompany,
        public bool $isHiringDevs,
        public int $relevance,
        public string $descriptionAr,
        public string $reasonAr,
        public array $services = [],
        public array $techHints = [],
        public ?string $city = null,
        public ?string $driver = null,
    ) {}

    public function withDriver(string $driver): self
    {
        return new self(
            $this->isSoftwareCompany, $this->isHiringDevs, $this->relevance, $this->descriptionAr,
            $this->reasonAr, $this->services, $this->techHints, $this->city, $driver,
        );
    }
}
