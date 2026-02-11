<?php

namespace App\Services\LlmProviders;

use App\Contracts\LlmProviderInterface;
use App\DTOs\NormalizedAddress;
use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements LlmProviderInterface
{
    private string $apiKey;
    private string $model;
    private int $timeout;
    private int $maxRetries;

    public function __construct()
    {
        $this->apiKey = config('normalizer.openai.api_key');
        $this->model = config('normalizer.openai.model');
        $this->timeout = config('normalizer.openai.timeout');
        $this->maxRetries = config('normalizer.openai.max_retries');
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
        $results = $this->callApi(SystemPrompt::get(), $userContent);

        // If the result is a single object (not array), wrap it
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
        return 'openai';
    }

    private function callApi(string $systemPrompt, string $userContent): array
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            if ($attempt > 0) {
                usleep($attempt * 500_000); // Exponential backoff: 0.5s, 1s
            }

            try {
                $response = Http::withToken($this->apiKey)
                    ->timeout($this->timeout)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $this->model,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => $userContent],
                        ],
                        'temperature' => 0.1,
                        'max_tokens' => 2000,
                    ]);

                if ($response->failed()) {
                    throw NormalizationException::providerFailed(
                        'openai',
                        "HTTP {$response->status()}: {$response->body()}"
                    );
                }

                $content = $response->json('choices.0.message.content');

                if (! $content) {
                    throw NormalizationException::providerFailed('openai', 'Empty response content');
                }

                $decoded = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw NormalizationException::providerFailed('openai', 'Invalid JSON in response');
                }

                // Handle wrapped responses like {"addresses": [...]} or {"results": [...]}
                if (isset($decoded['addresses'])) {
                    return $decoded['addresses'];
                }
                if (isset($decoded['results'])) {
                    return $decoded['results'];
                }

                return $decoded;
            } catch (NormalizationException $e) {
                $lastException = $e;
                Log::warning("OpenAI attempt {$attempt} failed", ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $lastException = NormalizationException::providerFailed('openai', $e->getMessage());
                Log::warning("OpenAI attempt {$attempt} failed", ['error' => $e->getMessage()]);
            }
        }

        throw $lastException ?? NormalizationException::providerFailed('openai', 'Unknown error');
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

        return 'Normalize this address: ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private function formatBatchInput(array $inputs): string
    {
        $addresses = array_map(function (RawAddressInput $input, int $index) {
            return array_filter([
                'index' => $index,
                'country' => $input->country,
                'postal_code' => $input->postal_code,
                'city' => $input->city,
                'address' => $input->address,
                'full_name' => $input->full_name,
            ], fn ($v) => $v !== null && $v !== '');
        }, $inputs, array_keys($inputs));

        return 'Normalize these addresses and return a JSON array in the same order: ' .
            json_encode($addresses, JSON_UNESCAPED_UNICODE);
    }
}
