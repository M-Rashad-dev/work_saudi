<?php

namespace App\Services\Analysis\Drivers;

use App\Exceptions\InvalidLlmOutputException;
use App\Exceptions\LlmException;
use App\Services\Analysis\AnalysisPrompt;
use App\Services\Analysis\AnalysisValidator;
use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Optional local model on the VPS (e.g. qwen2.5:3b). Disabled unless OLLAMA_URL and OLLAMA_MODEL are set.
 */
class OllamaDriver extends PromptDriver
{
    public function __construct(AnalysisPrompt $prompt, AnalysisValidator $validator, private readonly Http $http)
    {
        parent::__construct($prompt, $validator);
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function isAvailable(): bool
    {
        return filled(config('outreach.llm.ollama.url')) && filled(config('outreach.llm.ollama.model'));
    }

    protected function complete(string $system, string $user): string
    {
        try {
            $response = $this->http
                ->asJson()
                ->timeout((int) config('outreach.llm.ollama.timeout', 120))
                ->retry([2000], throw: false)
                ->post(rtrim((string) config('outreach.llm.ollama.url'), '/').'/api/chat', [
                    'model' => config('outreach.llm.ollama.model'),
                    'stream' => false,
                    'format' => 'json',
                    'options' => ['temperature' => 0.2, 'num_ctx' => 8192],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => $user],
                    ],
                ]);
        } catch (Throwable $e) {
            throw new LlmException('ollama: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new LlmException('ollama: '.($response->json('error') ?? "HTTP {$response->status()}"));
        }

        $text = (string) $response->json('message.content', '');
        if (trim($text) === '') {
            throw new InvalidLlmOutputException('ollama: empty response');
        }

        return $text;
    }
}
