<?php

namespace App\Http\Controllers;

use App\Enums\BatchAction;
use App\Http\Requests\Outreach\UpdateBatchItemRequest;
use App\Jobs\RunDiscoveryJob;
use App\Models\DailyBatchItem;
use App\Services\Batch\BatchBuilder;
use App\Services\Discovery\DiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;

/** Home-page actions: build today's batch, run discovery now, mark a card. */
class BatchController extends Controller
{
    /** "جهّز دفعة اليوم" — quick, runs inline so the cards appear immediately. */
    public function store(BatchBuilder $builder): RedirectResponse
    {
        $result = $builder->build();

        return back()->with($result->added > 0 || $result->isComplete() ? 'success' : 'error', $result->message());
    }

    /** "شغّل البحث الآن" — takes minutes (polite delays), so it runs on the queue. */
    public function discover(): RedirectResponse
    {
        $lock = Cache::lock(DiscoveryService::LOCK, 1);
        if (! $lock->get()) {
            return back()->with('error', 'البحث شغّال بالفعل، انتظر حتى ينتهي.');
        }
        $lock->release();

        RunDiscoveryJob::dispatch();

        return back()->with('success', 'بدأ البحث في الخلفية. النتائج تظهر في صفحة البحث خلال دقائق.');
    }

    /** "تم الإرسال" / "تخطي" / "غير مناسبة" / "ردّوا" (and "pending" to undo), plus a note. */
    public function update(UpdateBatchItemRequest $request, DailyBatchItem $item): RedirectResponse
    {
        if ($request->has('action')) {
            $action = BatchAction::from($request->string('action')->toString());
            $item->action = $action;
            $item->action_at = $action === BatchAction::Pending ? null : now();
        }
        if ($request->has('note')) {
            $item->note = $request->input('note');
        }
        $item->save();

        return back();
    }
}
