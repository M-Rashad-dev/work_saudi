<?php

namespace App\Services\Analysis;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;

/**
 * Blocks until a request slot is free (e.g. max 10 Gemini requests/minute across all workers).
 * Backed by the cache store (Redis in production), so the limit is global, not per worker.
 */
class LlmThrottle
{
    public function await(string $key, int $perMinute, int $maxWaitSeconds = 120): void
    {
        $perMinute = max(1, $perMinute);
        $waited = 0;

        while (RateLimiter::tooManyAttempts("llm:{$key}", $perMinute)) {
            $wait = max(1, RateLimiter::availableIn("llm:{$key}"));
            if ($waited + $wait > $maxWaitSeconds) {
                break; // give up waiting; the API will 429 and we fall back
            }
            Sleep::for($wait)->seconds();
            $waited += $wait;
        }

        RateLimiter::hit("llm:{$key}", 60);
    }
}
