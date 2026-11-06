<?php

namespace App\Services\Analysis\Drivers;

use App\Exceptions\LlmException;
use App\Exceptions\LlmRateLimitedException;
use App\Services\Analysis\AnalysisPrompt;
use App\Services\Analysis\AnalysisValidator;
use App\Services\Analysis\LlmThrottle;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Google AI Studio free tier (API key, no billing).
 * Free-tier care: global throttle (LLM_RATE_PER_MINUTE), exponential backoff on 429/503,
 * and a 15-minute cool-down after a final 429 so we don't hammer an exhausted daily quota.
 */
class GeminiDriver extends PromptDriver
{
    public const COOLDOWN_KEY = 'llm:gemini:cooldown';

    public function __construct(
        AnalysisPrompt $prompt,
        AnalysisValidator $validator,
        private readonly Http $http,
        private readonly Cache $cache,
        private readonly LlmThrottle $throttle,
    ) {
        parent::__construct($prompt, $validator);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function isAvailable(): bool
    {
        return filled(config('outreach.llm.gemini.api_key')) && ! $this->cache->has(self::COOLDOWN_KEY);
    }

    protected function complete(string $system, string $user): string
    {
        $this->throttle->await($this->name(), (int) config('outreach.llm.rate_per_minute', 10));

        $model = (string) config('outreach.llm.gemini.model');
        $url = rtrim((string) config('outreach.llm.gemini.url'), '/')."/models/{$model}:generateContent";

        try {
            $response = $this->http
                ->withHeaders(['x-goog-api-key' => (string) config('outreach.llm.gemini.api_key')])
                ->asJson()
                ->timeout(60)
                ->retry(
                    (array) config('outreach.llm.retry_backoff_ms', [5000, 20000]),
                    when: fn (Throwable $e) => $e instanceof ConnectionException
                        || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 503], true)),
                    throw: false,
                )
                ->post($url, [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                        'maxOutputTokens' => 4096,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (Throwable $e) {
            throw new LlmException('gemini: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 429) {
            $this->cache->put(self::COOLDOWN_KEY, true, now()->addMinutes(15));
            throw new LlmRateLimitedException('gemini: rate limited / quota exhausted (429); cooling down 15 min');
        }

        if ($response->failed()) {
            $message = (string) ($response->json('error.message') ?? "HTTP {$response->status()}");
            throw new LlmException("gemini: {$message}");
        }

        if ($reason = $response->json('promptFeedback.blockReason')) {
            throw new LlmException("gemini: prompt blocked ({$reason})");
        }

        $text = collect((array) $response->json('candidates.0.content.parts', []))
            ->pluck('text')->filter()->implode('');

        if ($text === '') {
            $finish = $response->json('candidates.0.finishReason') ?? 'unknown';
            throw new \App\Exceptions\InvalidLlmOutputException("empty response (finishReason: {$finish})");
        }

        return $text;
    }
}
