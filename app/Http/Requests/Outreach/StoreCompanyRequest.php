<?php

namespace App\Http\Requests\Outreach;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $website = trim((string) $this->input('website'));
        if ($website !== '' && ! preg_match('#^https?://#i', $website)) {
            $website = 'https://'.$website;
        }

        $this->merge(['website' => $website !== '' ? $website : null]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'website' => ['nullable', 'url:http,https', 'max:500'],
            'city' => ['nullable', 'string', Rule::in(collect(config('outreach.cities'))->pluck('ar')->all())],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:191'],
            'job_post_url' => ['nullable', 'url:http,https', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'اسم الشركة', 'website' => 'الموقع', 'city' => 'المدينة', 'phone' => 'الهاتف',
            'whatsapp' => 'واتساب', 'email' => 'الإيميل', 'job_post_url' => 'رابط الوظيفة', 'note' => 'الملاحظة',
        ];
    }
}
