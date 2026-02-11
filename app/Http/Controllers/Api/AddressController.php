<?php

namespace App\Http\Controllers\Api;

use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BatchNormalizeRequest;
use App\Http\Requests\NormalizeAddressRequest;
use App\Models\RequestLog;
use App\Services\AddressNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Address Normalization', description: 'Endpoints for normalizing postal addresses')]
#[OA\Tag(name: 'Status', description: 'Health check and usage statistics')]
class AddressController extends Controller
{
    public function __construct(
        private readonly AddressNormalizer $normalizer,
    ) {}

    #[OA\Post(
        path: '/api/v1/normalize',
        summary: 'Normalize a single address',
        description: 'Accepts a raw postal address and returns a normalized, structured result. The address goes through a pipeline: regex pre-cleaning, cache lookup, optional Libpostal parsing, AI normalization (OpenAI/Anthropic), and post-validation.',
        security: [['bearerAuth' => []]],
        tags: ['Address Normalization'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['country', 'city', 'address'],
            properties: [
                new OA\Property(property: 'country', type: 'string', description: 'ISO 3166-1 alpha-2 country code', example: 'PL', minLength: 2, maxLength: 2),
                new OA\Property(property: 'postal_code', type: 'string', description: 'Postal/ZIP code', example: '00-001', nullable: true),
                new OA\Property(property: 'city', type: 'string', description: 'City name (may contain noise like company names)', example: 'Warszawa FHU Jan Kowalski', maxLength: 255),
                new OA\Property(property: 'address', type: 'string', description: 'Street address (may contain courier comments)', example: 'ul. Marszałkowska 1/2 proszę dzwonić przed dostawą', maxLength: 500),
                new OA\Property(property: 'full_name', type: 'string', description: 'Recipient full name — used for detecting names in wrong fields', example: 'Jan Kowalski', nullable: true, maxLength: 255),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Address normalized successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['ok'], example: 'ok'),
                new OA\Property(property: 'confidence', type: 'number', format: 'float', description: '0.0–1.0 confidence score', example: 0.95),
                new OA\Property(property: 'source', type: 'string', enum: ['cache', 'ai', 'libpostal+ai'], example: 'ai'),
                new OA\Property(property: 'data', ref: '#/components/schemas/NormalizedAddressData'),
                new OA\Property(property: 'removed_noise', type: 'array', items: new OA\Items(type: 'string'), example: ['proszę dzwonić przed dostawą']),
            ],
        ),
    )]
    #[OA\Response(
        response: 401,
        description: 'Unauthorized — missing or invalid Bearer token',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'Unauthenticated.'),
            ],
        ),
    )]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'The country field is required. (and 1 more error)'),
                new OA\Property(property: 'errors', type: 'object',
                    additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
                    example: ['country' => ['The country field is required.']],
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 429,
        description: 'Monthly usage limit exceeded',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'error'),
                new OA\Property(property: 'message', type: 'string', example: 'Monthly usage limit exceeded.'),
                new OA\Property(property: 'limit', type: 'integer', example: 10000),
                new OA\Property(property: 'used', type: 'integer', example: 10000),
            ],
        ),
    )]
    #[OA\Response(
        response: 503,
        description: 'All AI providers unavailable',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'error'),
                new OA\Property(property: 'message', type: 'string', example: 'All AI providers are currently unavailable. Please try again later.'),
            ],
        ),
    )]
    public function normalize(NormalizeAddressRequest $request): JsonResponse
    {
        $client = $request->user();

        $input = RawAddressInput::fromArray($request->validated());

        try {
            $result = $this->normalizer->normalize($input, $client);

            return response()->json($result);
        } catch (NormalizationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'All AI providers are currently unavailable. Please try again later.',
            ], 503);
        }
    }

    #[OA\Post(
        path: '/api/v1/normalize/batch',
        summary: 'Normalize a batch of addresses',
        description: 'Accepts up to 50 addresses and normalizes them. Addresses found in cache are returned immediately; only cache misses are sent to AI in a single prompt.',
        security: [['bearerAuth' => []]],
        tags: ['Address Normalization'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['addresses'],
            properties: [
                new OA\Property(
                    property: 'addresses',
                    type: 'array',
                    description: 'Array of addresses to normalize (max 50)',
                    maxItems: 50,
                    minItems: 1,
                    items: new OA\Items(
                        required: ['country', 'city', 'address'],
                        properties: [
                            new OA\Property(property: 'id', type: 'string', description: 'Optional ID for mapping results back', example: 'order_12345', nullable: true),
                            new OA\Property(property: 'country', type: 'string', example: 'PL', minLength: 2, maxLength: 2),
                            new OA\Property(property: 'postal_code', type: 'string', example: '00-001', nullable: true),
                            new OA\Property(property: 'city', type: 'string', example: 'Warszawa', maxLength: 255),
                            new OA\Property(property: 'address', type: 'string', example: 'Marszałkowska 1/2', maxLength: 500),
                            new OA\Property(property: 'full_name', type: 'string', example: 'Jan Kowalski', nullable: true, maxLength: 255),
                        ],
                        type: 'object',
                    ),
                ),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Batch normalization results',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(
                    property: 'results',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', example: 'order_12345', nullable: true),
                            new OA\Property(property: 'status', type: 'string', enum: ['ok', 'error'], example: 'ok'),
                            new OA\Property(property: 'confidence', type: 'number', format: 'float', example: 0.95),
                            new OA\Property(property: 'source', type: 'string', enum: ['cache', 'ai', 'libpostal+ai'], example: 'ai'),
                            new OA\Property(property: 'data', ref: '#/components/schemas/NormalizedAddressData'),
                            new OA\Property(property: 'error', type: 'string', description: 'Error message if status is error', nullable: true),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'stats',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'total', type: 'integer', example: 2),
                        new OA\Property(property: 'from_cache', type: 'integer', example: 1),
                        new OA\Property(property: 'from_ai', type: 'integer', example: 1),
                        new OA\Property(property: 'failed', type: 'integer', example: 0),
                    ],
                ),
            ],
        ),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 422,
        description: 'Validation error (e.g. more than 50 addresses)',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'The addresses field must not have more than 50 items.'),
                new OA\Property(property: 'errors', type: 'object'),
            ],
        ),
    )]
    #[OA\Response(response: 429, ref: '#/components/responses/RateLimitExceeded')]
    #[OA\Response(response: 503, ref: '#/components/responses/ProvidersUnavailable')]
    public function batch(BatchNormalizeRequest $request): JsonResponse
    {
        $client = $request->user();
        $validated = $request->validated();

        $inputs = array_map(
            fn (array $addr) => RawAddressInput::fromArray($addr),
            $validated['addresses']
        );

        try {
            $result = $this->normalizer->normalizeBatch($inputs, $client);

            return response()->json($result);
        } catch (NormalizationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'All AI providers are currently unavailable. Please try again later.',
            ], 503);
        }
    }

    #[OA\Get(
        path: '/api/v1/status',
        summary: 'Health check and usage statistics',
        description: 'Returns the current API client status including monthly usage, remaining quota, cache hit rate, and active AI provider.',
        security: [['bearerAuth' => []]],
        tags: ['Status'],
    )]
    #[OA\Response(
        response: 200,
        description: 'Client status and statistics',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'ok'),
                new OA\Property(property: 'client', type: 'string', description: 'Client name', example: 'sportello'),
                new OA\Property(property: 'plan_limit', type: 'integer', description: 'Monthly request limit', example: 10000),
                new OA\Property(property: 'used_this_month', type: 'integer', example: 1234),
                new OA\Property(property: 'remaining', type: 'integer', example: 8766),
                new OA\Property(property: 'cache_hit_rate', type: 'number', format: 'float', description: 'Ratio 0.0–1.0', example: 0.72),
                new OA\Property(property: 'active_provider', type: 'string', enum: ['openai', 'anthropic'], example: 'openai'),
            ],
        ),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    public function status(Request $request): JsonResponse
    {
        $client = $request->user();

        $totalRequests = RequestLog::where('api_client_id', $client->id)->count();
        $cacheHits = RequestLog::where('api_client_id', $client->id)
            ->where('source', 'cache')
            ->count();
        $cacheHitRate = $totalRequests > 0 ? round($cacheHits / $totalRequests, 2) : 0;

        return response()->json([
            'status' => 'ok',
            'client' => $client->name,
            'plan_limit' => $client->monthly_limit,
            'used_this_month' => $client->current_month_usage,
            'remaining' => $client->remainingQuota(),
            'cache_hit_rate' => $cacheHitRate,
            'active_provider' => $client->preferred_provider ?? config('normalizer.default_provider'),
        ]);
    }
}
