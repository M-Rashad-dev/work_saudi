<?php

namespace App\Http\Requests\Outreach;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'my_name' => ['required', 'string', 'max:100'],
            'cv_url' => ['nullable', 'url:http,https', 'max:500'],
            'portfolio_url' => ['nullable', 'url:http,https', 'max:500'],
            'message_template' => ['required', 'string', 'max:2000'],
            'email_subject' => ['required', 'string', 'max:255'],
            'email_body' => ['required', 'string', 'max:4000'],
            'batch_size' => ['required', 'integer', 'between:1,50'],
            'batch_time' => ['required', 'date_format:H:i'],
            'min_relevance' => ['required', 'integer', 'between:0,100'],
            'llm_drivers' => ['required', 'array', 'min:1'],
            'llm_drivers.*' => ['distinct', Rule::in(['gemini', 'ollama', 'heuristic'])],
        ];
    }

    public function attributes(): array
    {
        return [
            'my_name' => 'اسمي', 'cv_url' => 'رابط السيرة الذاتية', 'portfolio_url' => 'رابط الأعمال',
            'message_template' => 'نص الرسالة', 'email_subject' => 'عنوان الإيميل', 'email_body' => 'نص الإيميل',
            'batch_size' => 'عدد الشركات يومياً', 'batch_time' => 'وقت الدفعة', 'min_relevance' => 'أقل درجة ملاءمة',
            'llm_drivers' => 'محركات الذكاء الاصطناعي',
        ];
    }
}
