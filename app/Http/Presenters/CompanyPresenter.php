<?php

namespace App\Http\Presenters;

use App\Enums\ContactType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DailyBatchItem;
use App\Services\Batch\ContactSelector;
use App\Services\Batch\MessageRenderer;
use App\Services\Enrichment\EmailExtractor;
use App\Support\PhoneFormatter;

/**
 * Shapes companies for the Inertia pages. Expects `contacts` to be eager-loaded.
 */
class CompanyPresenter
{
    public function __construct(
        private readonly ContactSelector $selector,
        private readonly MessageRenderer $messages,
        private readonly EmailExtractor $emails,
    ) {}

    /** A card on the home page / archive. */
    public function card(DailyBatchItem $item): array
    {
        $company = $item->company;

        return [
            'id' => $item->id,
            'position' => $item->position,
            'action' => $item->action->value,
            'action_label' => $item->action->label(),
            'action_at' => $item->action_at?->toIso8601String(),
            'note' => $item->note,
            'company' => $this->summary($company) + $this->buttons($company),
        ];
    }

    /** Fields shown on cards and the detail page. */
    public function summary(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'city' => $company->city,
            'description_ar' => $company->description_ar,
            'reason_ar' => $company->reason_ar,
            'is_hiring_devs' => $company->is_hiring_devs,
            'is_software_company' => $company->is_software_company,
            'relevance' => $company->relevance,
            'tech_hints' => $company->tech_hints ?? [],
            'services' => $company->services ?? [],
            'website' => $company->website,
            'domain' => $company->domain,
            'maps_url' => $company->google_maps_url ?: Company::mapsUrlFor($company->name, $company->city),
            'job_post_url' => $company->job_post_url,
            'linkedin_url' => $company->linkedin_url,
            'phones' => $company->contacts->where('type', ContactType::Phone)->values()->map(fn (Contact $c) => $this->contact($c))->all(),
            'emails' => collect($this->emails->sort(
                $company->contacts->where('type', ContactType::Email)->values()->all()
            ))->map(fn (Contact $c) => $this->contact($c))->all(),
        ];
    }

    /** The WhatsApp / email buttons with prefilled messages. */
    public function buttons(Company $company): array
    {
        $whatsapp = $this->selector->whatsapp($company->contacts);
        $email = $this->selector->email($company->contacts);

        return [
            'whatsapp_url' => $whatsapp ? $this->messages->whatsappUrl($whatsapp->value, $company) : null,
            'whatsapp_display' => $whatsapp ? PhoneFormatter::display($whatsapp->value) : null,
            'mailto_url' => $email ? $this->messages->mailtoUrl($email->value, $company) : null,
            'email_display' => $email?->value,
            'email_only' => $whatsapp === null && $email !== null,
        ];
    }

    private function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'type' => $contact->type->value,
            'value' => $contact->value,
            'display' => $contact->type === ContactType::Phone ? PhoneFormatter::display($contact->value) : $contact->value,
            'label' => $contact->label->value,
            'label_text' => $contact->label->label(),
            'is_mobile' => $contact->is_mobile,
            'is_whatsapp' => $contact->is_whatsapp,
            'can_whatsapp' => $contact->canWhatsapp(),
            'source_url' => $contact->source_url,
        ];
    }

    /** Selector used by eager loading everywhere cards are rendered. */
    public static function with(): array
    {
        return ['company.contacts'];
    }
}
