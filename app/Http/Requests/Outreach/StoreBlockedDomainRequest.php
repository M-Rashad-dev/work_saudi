<?php

namespace App\Http\Requests\Outreach;

use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedDomainRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Accept "https://www.Example.com/path" and store "example.com".
        $value = mb_strtolower(trim((string) $this->input('domain')));
        $value = preg_replace('#^[a-z]+://#', '', $value);
        $value = preg_replace('#^www\.#', '', explode('/', $value)[0]);

        $this->merge(['domain' => $value]);
    }

    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:191', 'regex:/^[\p{L}\p{N}-]+(\.[\p{L}\p{N}-]+)*$/u', 'unique:blocked_domains,domain'],
            'category' => ['nullable', 'in:job_board,directory,social,news,gov,reference,other'],
        ];
    }

    public function attributes(): array
    {
        return ['domain' => 'النطاق', 'category' => 'التصنيف'];
    }
}
