<?php

namespace App\Services\Batch;

use App\Enums\CompanyStatus;
use App\Enums\ContactType;
use App\Enums\SearchRunType;
use App\Models\Company;
use App\Models\DailyBatch;
use App\Models\DailyBatchItem;
use App\Models\SearchRun;
use App\Services\Settings\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the day's batch: the top N qualified companies never shown before.
 *
 * Order: has a WhatsApp-capable number → is hiring devs → relevance (→ oldest first).
 * When fewer than N have WhatsApp, email-only companies fill the rest. Companies with
 * neither (landline only) are never picked — there is no button to act on.
 *
 * Running it again the same day tops the batch up to N instead of creating a second one.
 * A company can be in one batch only, ever (unique daily_batch_items.company_id).
 */
class BatchBuilder
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function build(?CarbonInterface $date = null): BatchResult
    {
        $date ??= now();
        $day = $date->toDateString();

        return Cache::lock('outreach:build-batch', 60)->block(30, function () use ($day) {
            $run = SearchRun::start(SearchRunType::BuildBatch);
            $size = $this->settings->batchSize();

            [$batch, $added, $created] = DB::transaction(function () use ($day, $size) {
                $batch = DailyBatch::query()->whereDate('date', $day)->first();
                $created = $batch === null;
                $batch ??= DailyBatch::query()->create(['date' => $day, 'generated_at' => now()]);

                $existing = $batch->items()->count();
                $need = max(0, $size - $existing);
                if ($need === 0) {
                    return [$batch, collect(), $created];
                }

                $picked = $this->candidates()->limit($need)->get();
                $position = (int) $batch->items()->max('position');

                foreach ($picked as $company) {
                    DailyBatchItem::query()->create([
                        'daily_batch_id' => $batch->id,
                        'company_id' => $company->id,
                        'position' => ++$position,
                    ]);
                    $company->forceFill(['status' => CompanyStatus::Shown])->save();
                }

                $batch->forceFill(['generated_at' => now()])->save();

                return [$batch, $picked, $created];
            });

            $total = $batch->items()->count();
            $whatsapp = $added->where('has_whatsapp', true)->count();
            $result = new BatchResult($batch, $added->count(), $total, $size, $whatsapp, $created);

            if ($total < $size) {
                $run->addError("الدفعة ناقصة: {$total} من {$size} — لا توجد شركات مؤهلة كفاية", ['pool' => $this->poolSize()]);
            }
            $run->finish([
                'date' => $day,
                'added' => $result->added,
                'total' => $total,
                'size' => $size,
                'whatsapp' => $whatsapp,
                'email_only' => $result->added - $whatsapp,
                'pool_remaining' => $this->poolSize(),
            ]);

            return $result;
        });
    }

    /** Qualified, never shown, actionable — in batch order. */
    public function candidates(): Builder
    {
        $whatsappCapable = fn (Builder $q) => $q
            ->where('type', ContactType::Phone)
            ->where(fn (Builder $q) => $q->where('is_whatsapp', true)->orWhere('is_mobile', true));

        return Company::query()
            ->availableForBatch()
            ->where(fn (Builder $q) => $q
                ->whereHas('contacts', $whatsappCapable)
                ->orWhereHas('contacts', fn (Builder $q) => $q->where('type', ContactType::Email)))
            ->withExists(['contacts as has_whatsapp' => $whatsappCapable])
            ->orderByDesc('has_whatsapp')
            ->orderByDesc('is_hiring_devs')
            ->orderByDesc('relevance')
            ->orderBy('id');
    }

    public function poolSize(): int
    {
        return $this->candidates()->count();
    }
}
