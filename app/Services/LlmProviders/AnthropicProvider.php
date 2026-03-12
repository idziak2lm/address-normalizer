<?php

namespace App\Services\LlmProviders;

use App\Contracts\LlmProviderInterface;
use App\DTOs\NormalizedAddress;
use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Services\StreetParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnthropicProvider implements LlmProviderInterface
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;

    public function __construct(
        private readonly StreetParser $streetParser = new StreetParser,
    ) {
        $this->apiKey = config('normalizer.anthropic.api_key');
        $this->model = config('normalizer.anthropic.model');
        $this->timeout = config('normalizer.anthropic.timeout');
        $this->maxRetries = config('normalizer.anthropic.max_retries');
    }

    public function normalize(RawAddressInput $input): NormalizedAddress
    {
        $results = $this->callApi(
            SystemPrompt::get(),
            $this->formatSingleInput($input)
        );

        return NormalizedAddress::fromArray($results);
    }

    public function normalizeBatch(array $inputs): array
    {
        if (empty($inputs)) {
            return [];
        }

        if (count($inputs) === 1) {
            return [$this->normalize($inputs[0])];
        }

        $userContent = $this->formatBatchInput($inputs);
        $batchTimeout = max($this->timeout, count($inputs) * 3);
        $batchMaxTokens = count($inputs) * 200;
        $results = $this->callApi(SystemPrompt::get(), $userContent, $batchTimeout, $batchMaxTokens);

        if (isset($results['country_code'])) {
            $results = [$results];
        }

        return array_map(
            fn (array $item) => NormalizedAddress::fromArray($item),
            $results
        );
    }

    public function name(): string
    {
        return 'anthropic';
    }

    private function callApi(string $systemPrompt, string $userContent, ?int $timeout = null, ?int $maxTokens = null): array
    {
        $lastException = null;
        $timeout ??= $this->timeout;
        $maxTokens ??= 2000;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep($attempt * 500_000);
            }

            try {
                $response = Http::withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                    'content-type' => 'application/json',
                ])
                    ->timeout($timeout)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $this->model,
                        'max_tokens' => $maxTokens,
                        'system' => $systemPrompt,
                        'messages' => [
                            ['role' => 'user', 'content' => $userContent],
                        ],
                        'temperature' => 0.1,
                    ]);

                if ($response->failed()) {
                    throw NormalizationException::providerFailed(
                        'anthropic',
                        "HTTP {$response->status()}: {$response->body()}"
                    );
                }

                $content = $response->json('content.0.text');

                if (! $content) {
                    throw NormalizationException::providerFailed('anthropic', 'Empty response content');
                }

                // Anthropic may wrap JSON in markdown code blocks
                $content = $this->extractJson($content);

                $decoded = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw NormalizationException::providerFailed('anthropic', 'Invalid JSON in response');
                }

                if (isset($decoded['addresses'])) {
                    return $decoded['addresses'];
                }
                if (isset($decoded['results'])) {
                    return $decoded['results'];
                }

                return $decoded;
            } catch (NormalizationException $e) {
                $lastException = $e;
                Log::warning("Anthropic attempt {$attempt} failed", ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $lastException = NormalizationException::providerFailed('anthropic', $e->getMessage());
                Log::warning("Anthropic attempt {$attempt} failed", ['error' => $e->getMessage()]);
            }
        }

        throw $lastException ?? NormalizationException::providerFailed('anthropic', 'Unknown error');
    }

    private function extractJson(string $content): string
    {
        // Strip markdown code fences if present
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            return trim($matches[1]);
        }

        return trim($content);
    }

    private function formatSingleInput(RawAddressInput $input): string
    {
        $data = array_filter([
            'country' => $input->country,
            'postal_code' => $input->postal_code,
            'city' => $input->city,
            'address' => $input->address,
            'full_name' => $input->full_name,
        ], fn ($v) => $v !== null && $v !== '');

        $message = 'Normalize this address and return ONLY valid JSON: ' . json_encode($data, JSON_UNESCAPED_UNICODE);

        $hint = $this->streetParser->formatHint($input->country, $input->address);
        if ($hint) {
            $message .= "\n\nHint (regex pre-parse, use as suggestion only): " . $hint;
        }

        return $message;
    }

    private function formatBatchInput(array $inputs): string
    {
        $items = [];
        foreach ($inputs as $index => $input) {
            $data = array_filter([
                'index' => $index,
                'country' => $input->country,
                'postal_code' => $input->postal_code,
                'city' => $input->city,
                'address' => $input->address,
                'full_name' => $input->full_name,
            ], fn ($v) => $v !== null && $v !== '');

            $hint = $this->streetParser->formatHint($input->country, $input->address);
            if ($hint) {
                $data['_hint'] = $hint;
            }

            $items[] = $data;
        }

        return 'Normalize these addresses and return ONLY a valid JSON array in the same order: ' .
            json_encode($items, JSON_UNESCAPED_UNICODE);
    }
}
