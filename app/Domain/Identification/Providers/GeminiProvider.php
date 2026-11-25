<?php

namespace App\Domain\Identification\Providers;

use App\Domain\Identification\Contracts\IdentificationProvider;
use App\Domain\Identification\Data\IdentificationInput;
use App\Domain\Identification\Data\ProviderResult;
use App\Domain\Identification\Data\RerankInput;
use App\Domain\Identification\Exceptions\InvalidProviderOutput;
use App\Domain\Identification\Exceptions\ProviderRateLimited;
use App\Domain\Identification\Exceptions\ProviderUnavailable;
use App\Domain\Identification\Prompts\IdentificationPrompts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini via the REST generateContent endpoint (no SDK lock-in).
 * The API key lives only on the server (backend-controlled AI requests).
 */
class GeminiProvider implements IdentificationProvider
{
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'gemini';
    }

    public function model(): string
    {
        return $this->config['model'];
    }

    public function identify(IdentificationInput $input): ProviderResult
    {
        return $this->call($this->model(), IdentificationPrompts::open($input), $input, IdentificationPrompts::responseSchema(false));
    }

    public function rerank(RerankInput $input): ProviderResult
    {
        $model = $this->config['rerank_model'] ?: $this->model();

        return $this->call($model, IdentificationPrompts::rerank($input), $input->base, IdentificationPrompts::responseSchema(true));
    }

    private function call(string $model, string $prompt, IdentificationInput $input, array $schema): ProviderResult
    {
        if (blank($this->config['api_key'] ?? null)) {
            throw new ProviderUnavailable('GEMINI_API_KEY is not configured.');
        }

        $generationConfig = [
            'temperature' => $this->config['temperature'] ?? 0.2,
            'maxOutputTokens' => $this->config['max_output_tokens'] ?? 1024,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];
        if (! empty($this->config['thinking_config'])) {
            $generationConfig['thinkingConfig'] = $this->config['thinking_config'];
        }

        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt],
                    ['inline_data' => ['mime_type' => $input->mime, 'data' => base64_encode($input->imageBytes)]],
                ],
            ]],
            'generationConfig' => $generationConfig,
        ];

        $started = hrtime(true);

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->config['api_key']])
                ->timeout($this->config['timeout'] ?? 20)
                ->connectTimeout(5)
                ->acceptJson()
                ->post(rtrim($this->config['base_url'], '/')."/models/{$model}:generateContent", $body);
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('Gemini connection failed: '.$e->getMessage(), null, $this->elapsed($started), isTimeout: true);
        }

        $latency = $this->elapsed($started);
        $this->throwForStatus($response, $latency);

        $usage = $response->json('usageMetadata', []);
        $inputTokens = (int) ($usage['promptTokenCount'] ?? 0);
        // Thinking tokens are billed as output.
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);

        $text = collect($response->json('candidates.0.content.parts', []))
            ->filter(fn ($p) => isset($p['text']) && empty($p['thought']))
            ->pluck('text')->implode('');

        $json = json_decode($this->stripFences($text), true);
        if (! is_array($json)) {
            $finish = $response->json('candidates.0.finishReason');
            throw new InvalidProviderOutput("Unparseable Gemini output (finishReason={$finish}).", $response->status(), $latency, $inputTokens, $outputTokens);
        }

        return ProviderResult::fromStructured($json, $inputTokens, $outputTokens, $latency, $model);
    }

    private function throwForStatus(Response $response, int $latency): void
    {
        if ($response->successful()) {
            return;
        }

        $message = (string) ($response->json('error.message') ?? $response->body());
        $status = $response->status();

        if ($status === 429 || $response->json('error.status') === 'RESOURCE_EXHAUSTED') {
            throw new ProviderRateLimited('Gemini quota exhausted: '.mb_substr($message, 0, 300), $status, $latency);
        }

        throw new ProviderUnavailable("Gemini HTTP {$status}: ".mb_substr($message, 0, 300), $status, $latency);
    }

    private function stripFences(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;
        }

        return $text;
    }

    private function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1e6);
    }
}
