<?php

namespace App\Services\Batch;

use App\Enums\ContactLabel;
use App\Enums\ContactType;
use App\Models\Contact;
use App\Services\Enrichment\EmailExtractor;
use Illuminate\Support\Collection;

/**
 * Which contact a card's buttons use.
 * WhatsApp: number from a wa.me link > HR/careers mobile > any mobile. Landlines never.
 * Email: hr@ > careers@ > info@ > contact@ > others (same order as extraction).
 */
class ContactSelector
{
    public function __construct(private readonly EmailExtractor $emails) {}

    /** @param Collection<int, Contact> $contacts */
    public function whatsapp(Collection $contacts): ?Contact
    {
        return $contacts
            ->filter(fn (Contact $c) => $c->canWhatsapp())
            ->sortBy([
                fn (Contact $a, Contact $b) => $b->is_whatsapp <=> $a->is_whatsapp,
                fn (Contact $a, Contact $b) => $this->isHr($b) <=> $this->isHr($a),
                fn (Contact $a, Contact $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    /** @param Collection<int, Contact> $contacts */
    public function email(Collection $contacts): ?Contact
    {
        return $contacts
            ->filter(fn (Contact $c) => $c->type === ContactType::Email)
            ->sortBy([
                fn (Contact $a, Contact $b) => $this->emails->priority($a->value) <=> $this->emails->priority($b->value),
                fn (Contact $a, Contact $b) => $this->isHr($b) <=> $this->isHr($a),
                fn (Contact $a, Contact $b) => $a->id <=> $b->id,
            ])
            ->first();
    }

    private function isHr(Contact $contact): bool
    {
        return in_array($contact->label, [ContactLabel::Hr, ContactLabel::Careers], true);
    }
}
