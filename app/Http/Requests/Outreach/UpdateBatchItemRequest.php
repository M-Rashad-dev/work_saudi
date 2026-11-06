<?php

namespace App\Http\Requests\Outreach;

use App\Enums\BatchAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBatchItemRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'required', Rule::enum(BatchAction::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function attributes(): array
    {
        return ['action' => 'الإجراء', 'note' => 'الملاحظة'];
    }
}
