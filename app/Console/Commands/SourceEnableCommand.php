<?php

namespace App\Console\Commands;

use App\Services\Discovery\SourceState;
use Illuminate\Console\Command;

class SourceEnableCommand extends Command
{
    protected $signature = 'outreach:source-enable {source=serper : Source name}';

    protected $description = 'Re-enable a source that disabled itself (e.g. Serper after topping up credits)';

    public function handle(SourceState $state): int
    {
        $source = (string) $this->argument('source');
        $info = $state->info($source);

        if ($info === null) {
            $this->info("{$source} is not disabled.");

            return self::SUCCESS;
        }

        $state->enable($source);
        $this->info("{$source} re-enabled (was disabled at {$info['at']}: {$info['reason']}).");

        return self::SUCCESS;
    }
}
