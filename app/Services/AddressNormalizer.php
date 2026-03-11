<?php

namespace App\Services;

use App\Contracts\AddressParserInterface;
use App\Contracts\LlmProviderInterface;
use App\DTOs\NormalizedAddress;
use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Models\ApiClient;
use App\Models\RequestLog;
use Illuminate\Support\Facades\Log;

class AddressNormalizer
{
    public function __construct(
        private readonly PreCleaner $preCleaner,
        private readonly CacheManager $cacheManager,
        private readonly PostValidator $postValidator,
        private readonly LlmProviderInterface $primaryProvider,
        private readonly ?LlmProviderInterface $fallbackProvider = null,
        private readonly ?AddressParserInterface $addressParser = null,
        private readonly ?GoogleAddressValidationClient $googleValidator = null,
    ) {}

    /**
     * Normalize a single address through the full pipeline.
     */
    public function normalize(RawAddressInput $input, ApiClient $client, ?bool $googleValidate = null): array
    {
        $startTime = microtime(true);

        try {
            // Step 1: Pre-clean
            $cleaned = $this->preCleaner->clean($input);

            // Step 2: Cache lookup
            $cached = $this->cacheManager->lookup($cleaned);
            if ($cached) {
                $this->logRequest($client, $input, $cached, 'cache', null, $startTime);

                return $this->formatResponse($cached, 'cache');
            }

            // Step 3: Optional Libpostal pre-parse
            $source = 'ai';
            if ($this->addressParser?->isAvailable()) {
                $this->addressParser->parse($cleaned);
                $source = 'libpostal+ai';
            }

            // Step 4: AI normalization with fallback
            $result = $this->normalizeWithFallback($cleaned);

            // Step 5: Post-validate (pass raw address for regex cross-check)
            $result = $this->postValidator->validate($result, $cleaned->address);

            // Step 6: Google Address Validation (optional, controlled per-request)
            $result = $this->applyGoogleValidation($result, $googleValidate);

            // Step 7: Cache store
            $this->cacheManager->store($cleaned, $result);

            // Step 8: Log
            $provider = $this->getUsedProvider($cleaned);
            $this->logRequest($client, $input, $result, $source, $provider, $startTime);

            return $this->formatResponse($result, $source);
        } catch (NormalizationException $e) {
            $this->logError($client, $input, $e, $startTime);
            throw $e;
        }
    }

    /**
     * Normalize a batch of addresses.
     */
    public function normalizeBatch(array $inputs, ApiClient $client, ?bool $googleValidate = null): array
    {
        $results = [];
        $toNormalize = [];
        $toNormalizeIndices = [];

        // Step 1: Pre-clean all and check cache
        foreach ($inputs as $index => $input) {
            $cleaned = $this->preCleaner->clean($input);
            $cached = $this->cacheManager->lookup($cleaned);

            if ($cached) {
                $results[$index] = [
                    'id' => $input->id,
                    'status' => 'ok',
                    'confidence' => $cached->confidence,
                    'source' => 'cache',
                    'data' => $cached->toArray(),
                ];
            } else {
                $toNormalize[] = $cleaned;
                $toNormalizeIndices[] = $index;
            }
        }

        // Step 2: Normalize cache misses via AI
        if (! empty($toNormalize)) {
            try {
                $aiResults = $this->normalizeBatchWithFallback($toNormalize);

                foreach ($aiResults as $i => $result) {
                    $originalIndex = $toNormalizeIndices[$i];
                    $result = $this->postValidator->validate($result, $toNormalize[$i]->address);
                    $result = $this->applyGoogleValidation($result, $googleValidate);
                    $this->cacheManager->store($toNormalize[$i], $result);

                    $source = 'ai';
                    if ($this->addressParser?->isAvailable()) {
                        $source = 'libpostal+ai';
                    }

                    $results[$originalIndex] = [
                        'id' => $inputs[$originalIndex]->id,
                        'status' => 'ok',
                        'confidence' => $result->confidence,
                        'source' => $source,
                        'data' => $result->toArray(),
                    ];
                }
            } catch (NormalizationException $e) {
                foreach ($toNormalizeIndices as $originalIndex) {
                    $results[$originalIndex] = [
                        'id' => $inputs[$originalIndex]->id,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        // Sort by original index
        ksort($results);

        // Calculate stats
        $fromCache = count(array_filter($results, fn ($r) => ($r['source'] ?? '') === 'cache'));
        $fromAi = count(array_filter($results, fn ($r) => in_array($r['source'] ?? '', ['ai', 'libpostal+ai'])));
        $failed = count(array_filter($results, fn ($r) => ($r['status'] ?? '') === 'error'));

        // Log batch
        $this->logBatch($client, count($inputs), $fromCache, $fromAi, $failed);

        return [
            'status' => 'ok',
            'results' => array_values($results),
            'stats' => [
                'total' => count($inputs),
                'from_cache' => $fromCache,
                'from_ai' => $fromAi,
                'failed' => $failed,
            ],
        ];
    }

    private function applyGoogleValidation(NormalizedAddress $result, ?bool $googleValidate = null): NormalizedAddress
    {
        // Per-request override: false = skip, true = force, null = use server config
        if ($googleValidate === false) {
            return $result;
        }

        if ($googleValidate !== true && ! $this->googleValidator?->isEnabled()) {
            return $result;
        }

        if (! $this->googleValidator) {
            return $result;
        }

        $validation = $this->googleValidator->validate($result);

        if (! $validation) {
            return $result;
        }

        return $this->googleValidator->applyCorrections($result, $validation);
    }

    private function normalizeWithFallback(RawAddressInput $input): NormalizedAddress
    {
        try {
            return $this->primaryProvider->normalize($input);
        } catch (NormalizationException $e) {
            Log::warning('Primary provider failed, falling back', ['error' => $e->getMessage()]);

            if ($this->fallbackProvider) {
                try {
                    return $this->fallbackProvider->normalize($input);
                } catch (NormalizationException $e2) {
                    Log::error('Fallback provider also failed', ['error' => $e2->getMessage()]);
                }
            }

            throw NormalizationException::allProvidersFailed();
        }
    }

    private function normalizeBatchWithFallback(array $inputs): array
    {
        try {
            return $this->primaryProvider->normalizeBatch($inputs);
        } catch (NormalizationException $e) {
            Log::warning('Primary provider batch failed, falling back', ['error' => $e->getMessage()]);

            if ($this->fallbackProvider) {
                try {
                    return $this->fallbackProvider->normalizeBatch($inputs);
                } catch (NormalizationException $e2) {
                    Log::error('Fallback provider batch also failed', ['error' => $e2->getMessage()]);
                }
            }

            throw NormalizationException::allProvidersFailed();
        }
    }

    private function formatResponse(NormalizedAddress $address, string $source): array
    {
        return [
            'status' => 'ok',
            'confidence' => $address->confidence,
            'source' => $source,
            'data' => $address->toArray(),
            'removed_noise' => $address->removed_noise,
        ];
    }

    private function logRequest(
        ApiClient $client,
        RawAddressInput $input,
        NormalizedAddress $result,
        string $source,
        ?string $provider,
        float $startTime,
    ): void {
        $processingTime = (int) ((microtime(true) - $startTime) * 1000);

        RequestLog::create([
            'api_client_id' => $client->id,
            'source' => $source,
            'provider' => $provider,
            'raw_input' => $input->toArray(),
            'normalized_output' => $result->toArray(),
            'confidence' => $result->confidence,
            'processing_time_ms' => $processingTime,
            'is_successful' => true,
            'country_code' => $result->country_code,
            'created_at' => now(),
        ]);
    }

    private function logError(
        ApiClient $client,
        RawAddressInput $input,
        NormalizationException $e,
        float $startTime,
    ): void {
        $processingTime = (int) ((microtime(true) - $startTime) * 1000);

        RequestLog::create([
            'api_client_id' => $client->id,
            'source' => 'ai',
            'provider' => null,
            'raw_input' => $input->toArray(),
            'normalized_output' => null,
            'confidence' => null,
            'processing_time_ms' => $processingTime,
            'is_successful' => false,
            'error_message' => $e->getMessage(),
            'country_code' => null,
            'created_at' => now(),
        ]);
    }

    private function logBatch(ApiClient $client, int $total, int $fromCache, int $fromAi, int $failed): void
    {
        Log::info('Batch normalization completed', [
            'client' => $client->name,
            'total' => $total,
            'from_cache' => $fromCache,
            'from_ai' => $fromAi,
            'failed' => $failed,
        ]);
    }

    private function getUsedProvider(RawAddressInput $input): string
    {
        return $this->primaryProvider->name();
    }
}
