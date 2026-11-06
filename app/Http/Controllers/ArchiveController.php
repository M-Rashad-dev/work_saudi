<?php

namespace App\Http\Controllers;

use App\Enums\BatchAction;
use App\Http\Presenters\CompanyPresenter;
use App\Models\DailyBatch;
use App\Models\DailyBatchItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** "الأرشيف": past batches by date, filterable by action. */
class ArchiveController extends Controller
{
    public function __invoke(Request $request, CompanyPresenter $presenter): Response
    {
        $filters = $request->validate(['action' => ['nullable', Rule::enum(BatchAction::class)]]);
        $action = isset($filters['action']) ? BatchAction::from($filters['action']) : null;

        $onlyAction = fn (Builder|\Illuminate\Database\Eloquent\Relations\HasMany $q) => $q->when($action, fn ($q) => $q->where('action', $action));

        $batches = DailyBatch::query()
            ->when($action, fn (Builder $q) => $q->whereHas('items', $onlyAction))
            ->with(['items' => fn ($q) => $onlyAction($q)->with(CompanyPresenter::with())])
            ->withCount('items')
            ->orderByDesc('date')
            ->paginate(7)
            ->withQueryString()
            ->through(fn (DailyBatch $batch) => [
                'id' => $batch->id,
                'date' => $batch->date->toDateString(),
                'date_label' => $batch->date->locale('ar')->translatedFormat('l j F Y'),
                'items_count' => $batch->items_count,
                'items' => $batch->items->map(fn (DailyBatchItem $item) => $presenter->card($item))->values(),
            ]);

        $counts = DailyBatchItem::query()->selectRaw('action, count(*) as n')->groupBy('action')->pluck('n', 'action');

        return Inertia::render('Archive/Index', [
            'batches' => $batches,
            'filters' => ['action' => $action?->value],
            'actions' => collect(BatchAction::cases())->map(fn (BatchAction $a) => [
                'value' => $a->value, 'label' => $a->label(), 'count' => (int) ($counts[$a->value] ?? 0),
            ]),
        ]);
    }
}
