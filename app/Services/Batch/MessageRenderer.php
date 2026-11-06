<?php

namespace App\Services\Batch;

use App\Enums\SettingKey;
use App\Models\Company;
use App\Services\Settings\SettingsRepository;

/**
 * Fills the editable templates and builds the click-to-send links.
 * Nothing is ever sent automatically — these are links the user clicks.
 */
class MessageRenderer
{
    public const PLACEHOLDERS = ['{company_name}', '{my_name}', '{cv_url}', '{portfolio_url}'];

    public function __construct(private readonly SettingsRepository $settings) {}

    public function render(string $template, string $companyName): string
    {
        return strtr($template, [
            '{company_name}' => $companyName,
            '{my_name}' => (string) $this->settings->get(SettingKey::MyName),
            '{cv_url}' => (string) $this->settings->get(SettingKey::CvUrl),
            '{portfolio_url}' => (string) $this->settings->get(SettingKey::PortfolioUrl),
        ]);
    }

    public function message(Company $company): string
    {
        return $this->render((string) $this->settings->get(SettingKey::MessageTemplate), $company->name);
    }

    /** https://wa.me/<digits>?text=<message> — $e164 like "+966551234567". */
    public function whatsappUrl(string $e164, Company $company): string
    {
        return 'https://wa.me/'.preg_replace('/\D+/', '', $e164).'?text='.rawurlencode($this->message($company));
    }

    public function mailtoUrl(string $email, Company $company): string
    {
        $subject = $this->render((string) $this->settings->get(SettingKey::EmailSubject), $company->name);
        $body = $this->render((string) $this->settings->get(SettingKey::EmailBody), $company->name);

        // rawurlencode (%20, not "+") — mail clients decode "+" literally.
        return 'mailto:'.$email.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }
}
