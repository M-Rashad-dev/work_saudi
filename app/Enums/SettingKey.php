<?php

namespace App\Enums;

/**
 * Every user-editable setting, with its default value.
 * Defaults are used when a row is missing, so the app works even before seeding.
 */
enum SettingKey: string
{
    case MessageTemplate = 'message_template';
    case EmailSubject = 'email_subject';
    case EmailBody = 'email_body';
    case BatchSize = 'batch_size';
    case BatchTime = 'batch_time';
    case MinRelevance = 'min_relevance';
    case CvUrl = 'cv_url';
    case PortfolioUrl = 'portfolio_url';
    case MyName = 'my_name';
    case LlmDrivers = 'llm_drivers';

    public const DEFAULT_MESSAGE = <<<'TXT'
السلام عليكم ورحمة الله،
أنا {my_name}، Full Stack Web Developer (Laravel + React) بخبرة 4 سنوات.
تواصلت مع {company_name} لأني مهتم بالانضمام لفريقكم لو عندكم احتياج لمطور حالياً أو مستقبلاً.
السيرة الذاتية: {cv_url}
أعمالي: {portfolio_url}
شاكر وقتكم، ولو مش مناسب حالياً أعتذر عن الإزعاج.
TXT;

    public function default(): mixed
    {
        return match ($this) {
            self::MessageTemplate, self::EmailBody => self::DEFAULT_MESSAGE,
            self::EmailSubject => 'Full Stack Developer (Laravel + React) — {my_name}',
            self::BatchSize => 10,
            self::BatchTime => '09:00',
            self::MinRelevance => 50,
            self::CvUrl => '',
            self::PortfolioUrl => '',
            self::MyName => '',
            self::LlmDrivers => config('outreach.llm.drivers', ['gemini', 'ollama', 'heuristic']),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MessageTemplate => 'نص رسالة واتساب',
            self::EmailSubject => 'عنوان الإيميل',
            self::EmailBody => 'نص الإيميل',
            self::BatchSize => 'عدد الشركات يومياً',
            self::BatchTime => 'وقت تجهيز الدفعة',
            self::MinRelevance => 'أقل درجة ملاءمة',
            self::CvUrl => 'رابط السيرة الذاتية',
            self::PortfolioUrl => 'رابط الأعمال',
            self::MyName => 'اسمي',
            self::LlmDrivers => 'ترتيب محركات الذكاء الاصطناعي',
        };
    }
}
