<?php

namespace App\Enums;

enum BatchAction: string
{
    use HasOptions;

    case Pending = 'pending';
    case Sent = 'sent';
    case Skipped = 'skipped';
    case NotRelevant = 'not_relevant';
    case Replied = 'replied';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'لم يتم بعد',
            self::Sent => 'تم الإرسال',
            self::Skipped => 'تخطي',
            self::NotRelevant => 'غير مناسبة',
            self::Replied => 'ردّوا',
        };
    }

    /** Actions that count as "contacted" for progress and stats. */
    public function countsAsSent(): bool
    {
        return in_array($this, [self::Sent, self::Replied], true);
    }
}
