<?php

namespace App\Http\Requests\Outreach;

use App\Enums\QueryGroup;
use App\Models\SearchQuery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchQueryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('query')) {
            $this->merge(['query' => trim(preg_replace('/\s+/u', ' ', (string) $this->input('query')))]);
        }
        if ($this->input('city') === '') {
            $this->merge(['city' => null]);
        }
    }

    public function rules(): array
    {
        /** @var SearchQuery|null $current */
        $current = $this->route('searchQuery');
        $partial = $this->isMethod('patch');

        return [
            'query' => [$partial ? 'sometimes' : 'required', 'string', 'min:3', 'max:255',
                Rule::unique('search_queries', 'query')
                    ->where('language', $this->input('language', $current?->language ?? 'ar'))
                    ->ignore($current?->id)],
            'group' => [$partial ? 'sometimes' : 'required', Rule::enum(QueryGroup::class)],
            'language' => [$partial ? 'sometimes' : 'required', Rule::in(['ar', 'en'])],
            'city' => ['sometimes', 'nullable', Rule::in(array_keys(config('outreach.cities')))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['query' => 'جملة البحث', 'group' => 'النوع', 'language' => 'اللغة', 'city' => 'المدينة'];
    }
}
