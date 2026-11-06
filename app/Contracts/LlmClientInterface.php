<?php

namespace App\Contracts;

use App\DTO\CompanyAnalysisInput;
use App\DTO\CompanyAnalysisResult;
use App\Exceptions\LlmException;

/**
 * One analysis driver (Gemini, Ollama, Heuristic). Drivers are tried in the configured order;
 * any LlmException moves on to the next one.
 */
interface LlmClientInterface
{
    /** "gemini" | "ollama" | "heuristic" — stored in companies.llm_driver */
    public function name(): string;

    /** False when not configured (no key/URL) or cooling down after quota errors. */
    public function isAvailable(): bool;

    /** @throws LlmException */
    public function analyze(CompanyAnalysisInput $input): CompanyAnalysisResult;
}
