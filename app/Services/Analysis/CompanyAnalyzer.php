<?php

namespace App\Services\Analysis;

use App\Contracts\LlmClientInterface;
use App\DTO\CompanyAnalysisInput;
use App\DTO\CompanyAnalysisResult;
use App\Exceptions\AllDriversFailedException;
use App\Exceptions\LlmException;
use App\Services\Analysis\Drivers\GeminiDriver;
use App\Services\Analysis\Drivers\HeuristicDriver;
use App\Services\Analysis\Drivers\OllamaDriver;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\Log;

/**
 * Tries the drivers in the configured order (setting "llm_drivers", default from LLM_DRIVERS),
 * falling back on any LlmException. The result records which driver produced it.
 */
class CompanyAnalyzer
{
    /** @var array<string, LlmClientInterface> */
    private array $drivers;

    public function __construct(
        GeminiDriver $gemini,
        OllamaDriver $ollama,
        HeuristicDriver $heuristic,
        private readonly SettingsRepository $settings,
    ) {
        $this->drivers = [];
        foreach ([$gemini, $ollama, $heuristic] as $driver) {
            $this->drivers[$driver->name()] = $driver;
        }
    }

    /**
     * @param  list<string>|null  $only  driver names to use instead of the configured order
     *
     * @throws AllDriversFailedException
     */
    public function analyze(CompanyAnalysisInput $input, ?array $only = null): CompanyAnalysisResult
    {
        $errors = [];

        foreach ($this->orderedDrivers($only) as $driver) {
            if (! $driver->isAvailable()) {
                continue;
            }

            try {
                return $driver->analyze($input)->withDriver($driver->name());
            } catch (LlmException $e) {
                $errors[$driver->name()] = $e->getMessage();
                Log::info('LLM driver failed, falling back', ['driver' => $driver->name(), 'error' => $e->getMessage()]);
            }
        }

        throw new AllDriversFailedException($errors ?: ['none' => 'no driver available']);
    }

    /** @return list<LlmClientInterface> */
    public function orderedDrivers(?array $only = null): array
    {
        $names = $only ?: ($this->settings->llmDrivers() ?: array_keys($this->drivers));

        return array_values(array_filter(array_map(fn (string $n) => $this->drivers[strtolower(trim($n))] ?? null, $names)));
    }

    /** @return list<string> */
    public function availableDriverNames(): array
    {
        return array_keys(array_filter($this->drivers, fn (LlmClientInterface $d) => $d->isAvailable()));
    }
}
