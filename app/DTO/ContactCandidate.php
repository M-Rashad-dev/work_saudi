<?php

namespace App\DTO;

use App\Enums\ContactLabel;
use App\Enums\ContactType;

/** A phone or email found for a company, ready to be stored as a Contact. */
final readonly class ContactCandidate
{
    public function __construct(
        public ContactType $type,
        public string $value,
        public ContactLabel $label = ContactLabel::General,
        public bool $isMobile = false,
        public bool $isWhatsapp = false,
        public ?string $sourceUrl = null,
    ) {}

    /** Higher = better. Used to merge duplicates found on several pages. */
    public function rank(): int
    {
        return ($this->isWhatsapp ? 100 : 0)
            + (in_array($this->label, [ContactLabel::Hr, ContactLabel::Careers], true) ? 20 : 0)
            + ($this->isMobile ? 10 : 0);
    }
}
