<?php

namespace App\Services\Analysis;

use App\DTO\CompanyAnalysisResult;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Services\Settings\SettingsRepository;

/**
 * Applies an analysis to a company and decides its status:
 * qualified  ⇔ relevance ≥ min_relevance AND (software company OR hiring devs) AND ≥ 1 contact.
 */
class CompanyQualifier
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function apply(Company $company, CompanyAnalysisResult $result): void
    {
        $company->fill([
            'is_software_company' => $result->isSoftwareCompany,
            'is_hiring_devs' => $result->isHiringDevs,
            'relevance' => $result->relevance,
            'description_ar' => $result->descriptionAr,
            'reason_ar' => $result->reasonAr,
            'services' => $result->services,
            'tech_hints' => $result->techHints,
            'llm_driver' => $result->driver,
            'analyzed_at' => now(),
        ]);

        if ($company->city === null && $result->city !== null) {
            $company->city = $result->city;
            $company->google_maps_url = Company::mapsUrlFor($company->name, $result->city);
        }

        $this->decide($company);
        $company->save();
    }

    /** Reject without spending an LLM call: there is nobody to contact. */
    public function rejectWithoutContacts(Company $company): void
    {
        $company->forceFill([
            'reason_ar' => 'لا توجد وسيلة تواصل',
            'analyzed_at' => now(),
            'status' => $company->status === CompanyStatus::Shown ? CompanyStatus::Shown : CompanyStatus::Rejected,
        ])->save();
    }

    public function qualifies(Company $company): bool
    {
        return $company->relevance >= $this->settings->minRelevance()
            && ($company->is_software_company || $company->is_hiring_devs)
            && $company->contacts()->exists();
    }

    private function decide(Company $company): void
    {
        if ($company->status === CompanyStatus::Shown) {
            return; // already shown once: never back in the pool
        }

        $company->status = $this->qualifies($company) ? CompanyStatus::Qualified : CompanyStatus::Rejected;
    }
}
