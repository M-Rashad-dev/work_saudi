<?php

namespace App\Services\Batch;

use App\Models\DailyBatch;

final readonly class BatchResult
{
    public function __construct(
        public DailyBatch $batch,
        public int $added,
        public int $total,
        public int $size,
        public int $whatsapp,
        public bool $created,
    ) {}

    public function isComplete(): bool
    {
        return $this->total >= $this->size;
    }

    public function message(): string
    {
        if ($this->added === 0) {
            return $this->isComplete()
                ? 'دفعة اليوم جاهزة بالفعل.'
                : "لا توجد شركات مؤهلة جديدة الآن — الدفعة {$this->total} من {$this->size}.";
        }

        return "تمت إضافة {$this->added} شركة ({$this->whatsapp} بواتساب) — الدفعة {$this->total} من {$this->size}.";
    }
}
