<?php

namespace App\Services\Dashboard;

use App\Enums\BatchAction;
use App\Enums\SearchRunType;
use App\Models\DailyBatchItem;
use App\Models\SearchRun;
use App\Services\Analysis\AnalysisBacklog;
use App\Services\Discovery\SourceState;

/**
 * Stats widget + health banners for the home page.
 */
class DashboardData
{
    public function __construct(
        private readonly AnalysisBacklog $backlog,
        private readonly SourceState $sources,
    ) {}

    /** @return array{sent: int, replies: int, reply_rate: int, skipped: int, not_relevant: int, shown: int} */
    public function stats(): array
    {
        $counts = DailyBatchItem::query()->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');
        $count = fn (BatchAction $a) => (int) ($counts[$a->value] ?? 0);

        $replies = $count(BatchAction::Replied);
        $sent = $count(BatchAction::Sent) + $replies; // a reply implies it was sent

        return [
            'sent' => $sent,
            'replies' => $replies,
            'reply_rate' => $sent > 0 ? (int) round($replies / $sent * 100) : 0,
            'skipped' => $count(BatchAction::Skipped),
            'not_relevant' => $count(BatchAction::NotRelevant),
            'shown' => (int) $counts->sum(),
        ];
    }

    /** @return list<array{level: 'error'|'warning'|'info', message: string, detail?: string}> */
    public function banners(): array
    {
        $banners = [];

        $discover = SearchRun::query()->where('type', SearchRunType::Discover)->latest('started_at')->first();
        if ($discover === null) {
            $banners[] = ['level' => 'info', 'message' => 'لم يتم تشغيل البحث بعد. اضغط "شغّل البحث الآن" أو انتظر تشغيل الليلة.'];
        } elseif ($discover->finished_at === null && $discover->started_at->lt(now()->subHours(3))) {
            $banners[] = ['level' => 'error', 'message' => 'آخر تشغيل للبحث توقف قبل أن يكتمل ('.$discover->started_at->diffForHumans().').'];
        } elseif ($discover->hasErrors() && $discover->started_at->gt(now()->subDay())) {
            $errors = collect($discover->errors)->pluck('message');
            $banners[] = [
                'level' => ($discover->stats['new_companies'] ?? 0) === 0 ? 'error' : 'warning',
                'message' => 'آخر تشغيل للبحث ('.$discover->started_at->diffForHumans().') فيه '.$errors->count().' خطأ.',
                'detail' => $errors->take(3)->implode("\n"),
            ];
        }

        $pool = $this->backlog->poolSize();
        $target = $this->backlog->poolTarget();
        if ($pool < $target) {
            $banners[] = [
                'level' => $pool < 10 ? 'error' : 'warning',
                'message' => "الشركات المؤهلة المتاحة: {$pool} فقط (الهدف {$target}). البحث سيوسّع المدن والجمل تلقائياً.",
            ];
        }

        if ($serper = $this->sources->info('serper')) {
            $banners[] = ['level' => 'info', 'message' => 'Serper متوقف تلقائياً: '.mb_substr($serper['reason'], 0, 120)];
        }

        return $banners;
    }
}
