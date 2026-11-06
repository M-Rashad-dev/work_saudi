<?php

namespace App\Services\Analysis\Drivers;

use App\Contracts\LlmClientInterface;
use App\DTO\CompanyAnalysisInput;
use App\DTO\CompanyAnalysisResult;
use App\Exceptions\InvalidLlmOutputException;
use App\Services\Analysis\AnalysisPrompt;
use App\Services\Analysis\AnalysisValidator;

/**
 * Base for real LLM drivers: send the prompt, validate the JSON, retry ONCE on invalid output,
 * then give up (InvalidLlmOutputException) so the analyzer falls back to the next driver.
 */
abstract class PromptDriver implements LlmClientInterface
{
    public function __construct(
        protected readonly AnalysisPrompt $prompt,
        protected readonly AnalysisValidator $validator,
    ) {}

    /** Raw text completion. @throws \App\Exceptions\LlmException */
    abstract protected function complete(string $system, string $user): string;

    public function analyze(CompanyAnalysisInput $input): CompanyAnalysisResult
    {
        $system = $this->prompt->system();
        $user = $this->prompt->user($input);

        try {
            return $this->validator->parse($this->complete($system, $user));
        } catch (InvalidLlmOutputException $first) {
            try {
                return $this->validator->parse($this->complete($system, $user.$this->prompt->retryNote($first->getMessage())));
            } catch (InvalidLlmOutputException $second) {
                throw new InvalidLlmOutputException("{$this->name()}: invalid output twice ({$second->getMessage()})", previous: $second);
            }
        }
    }
}
