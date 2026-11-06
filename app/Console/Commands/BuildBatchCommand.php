<?php

namespace App\Console\Commands;

use App\Services\Batch\BatchBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BuildBatchCommand extends Command
{
    protected $signature = 'outreach:build-batch {--date= : Y-m-d (default: today)}';

    protected $description = "Build the day's batch of companies (WhatsApp first, then hiring, then relevance)";

    public function handle(BatchBuilder $builder): int
    {
        $date = $this->option('date') ? Carbon::parse((string) $this->option('date')) : now();

        $result = $builder->build($date);
        $this->info($result->message());

        $result->batch->load('items.company');
        $this->table(['#', 'company', 'city', 'relevance', 'hiring'], $result->batch->items->map(fn ($item) => [
            $item->position, $item->company->name, $item->company->city, $item->company->relevance, $item->company->is_hiring_devs ? 'yes' : '',
        ])->all());

        return self::SUCCESS;
    }
}
