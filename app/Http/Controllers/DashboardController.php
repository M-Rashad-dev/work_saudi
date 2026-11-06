<?php

namespace App\Http\Controllers;

use App\Enums\BatchAction;
use App\Http\Presenters\CompanyPresenter;
use App\Models\DailyBatch;
use App\Services\Dashboard\DashboardData;
use App\Services\Settings\SettingsRepository;
use Inertia\Inertia;
use Inertia\Response;

/** "اليوم": today's cards, progress, stats and health banners. */
class DashboardController extends Controller
{
    public function __invoke(CompanyPresenter $presenter, DashboardData $data, SettingsRepository $settings): Response
    {
        $batch = DailyBatch::query()
            ->whereDate('date', now()->toDateString())
            ->with(['items' => fn ($q) => $q->with(CompanyPresenter::with())])
            ->first();

        $items = $batch?->items ?? collect();
        $done = $items->filter(fn ($item) => $item->action !== BatchAction::Pending)->count();
        $sent = $items->filter(fn ($item) => $item->action->countsAsSent())->count();

        return Inertia::render('Dashboard', [
            'today' => now()->locale('ar')->translatedFormat('l j F Y'),
            'batch' => $batch ? [
                'id' => $batch->id,
                'generated_at' => $batch->generated_at?->locale('ar')->translatedFormat('H:i'),
                'items' => $items->map(fn ($item) => $presenter->card($item))->values(),
            ] : null,
            'progress' => ['sent' => $sent, 'done' => $done, 'total' => max($items->count(), $settings->batchSize())],
            'batchTime' => $settings->batchTime(),
            'stats' => $data->stats(),
            'banners' => $data->banners(),
            'actions' => BatchAction::options(),
        ]);
    }
}
