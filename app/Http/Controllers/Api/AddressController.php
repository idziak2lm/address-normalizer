<?php

namespace App\Http\Controllers\Api;

use App\DTOs\RawAddressInput;
use App\Exceptions\NormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\BatchNormalizeRequest;
use App\Http\Requests\NormalizeAddressRequest;
use App\Http\Requests\ValidateAddressRequest;
use App\Models\RequestLog;
use App\Services\AddressNormalizer;
use App\Services\GoogleAddressValidationClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Address Normalization',
    description: 'Normalize single or batch postal addresses. Send raw, messy address data and receive structured, validated results with confidence scores. Supports 31 European countries.',
)]
#[OA\Tag(
    name: 'Address Validation',
    description: 'Validate addresses directly via Google Address Validation API — get coordinates, quality flags, and deliverability checks without AI normalization.',
)]
#[OA\Tag(
    name: 'Status',
    description: 'Check API health, your monthly usage, remaining quota, cache hit rate, and which AI provider is active.',
)]
class AddressController extends Controller
{
    public function __construct(
        private readonly AddressNormalizer $normalizer,
        private readonly GoogleAddressValidationClient $googleValidator,
    ) {}

    #[OA\Post(
        path: '/api/v1/normalize',
        summary: 'Normalize a single address',
        description: <<<'DESC'
Accepts a raw postal address and returns a normalized, structured result.

**When to use:** For real-time normalization of individual addresses — e.g. at checkout, during order import, or when validating a single delivery address.

**Pipeline:** regex pre-cleaning → cache lookup → street parsing → AI normalization (OpenAI/Anthropic) → post-validation → optional Google validation → cache store.

**What it does:**
- Extracts company names from wrong fields (e.g. "Warszawa FHU Kowalski" → city: "Warszawa", company: "FHU Kowalski")
- Removes courier comments, phone numbers, emails from address fields
- Splits house/apartment numbers (e.g. "15/4" → house: "15", apartment: "4")
- Validates postal code format against country
- Optionally adds geographic coordinates via Google Address Validation

**Performance:** ~50ms for cache hits, ~1-3s for AI calls (first time).
DESC,
        security: [['bearerAuth' => []]],
        tags: ['Address Normalization'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['country', 'city', 'address'],
            properties: [
                new OA\Property(property: 'country', type: 'string', description: 'ISO 3166-1 alpha-2 country code. Supported: PL, CZ, SK, DE, AT, CH, FR, IT, ES, PT, NL, BE, LU, GB, IE, DK, SE, NO, FI, HU, RO, BG, HR, SI, GR, CY, MT, LT, LV, EE, UA.', example: 'PL', minLength: 2, maxLength: 2),
                new OA\Property(property: 'postal_code', type: 'string', description: 'Postal/ZIP code. If missing, the AI will try to infer it. If present in the city or address field, it will be extracted automatically.', example: '00-001', nullable: true),
                new OA\Property(property: 'city', type: 'string', description: 'City name. May contain noise — the system handles company names (e.g. "Warszawa FHU Kowalski"), postal codes (e.g. "00-950 Warszawa"), and other garbage mixed in.', example: 'Warszawa FHU Jan Kowalski', maxLength: 255),
                new OA\Property(property: 'address', type: 'string', description: 'Street address line. May contain: street prefixes (ul., al.), house/apartment numbers (15/4, 15 m. 4), courier comments, phone numbers — all will be parsed and cleaned.', example: 'ul. Marszałkowska 1/2 proszę dzwonić przed dostawą', maxLength: 500),
                new OA\Property(property: 'full_name', type: 'string', description: 'Recipient full name. Helps the AI distinguish personal names from company names when they appear in wrong fields. Recommended but not required.', example: 'Jan Kowalski', nullable: true, maxLength: 255),
                new OA\Property(property: 'google_validate', type: 'boolean', description: 'Override Google Address Validation for this request. `true` = force validation (requires API key configured on server), `false` = skip validation, omit = use server default.', example: true, nullable: true),
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
        $validated = $request->validated();

        $input = RawAddressInput::fromArray($validated);
        $googleValidate = isset($validated['google_validate']) ? (bool) $validated['google_validate'] : null;

        try {
            $result = $this->normalizer->normalize($input, $client, $googleValidate);

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
        description: <<<'DESC'
Accepts up to 50 addresses and normalizes them in a single request.

**When to use:** For bulk processing of multiple addresses — e.g. nightly order cleanup, CSV import pipeline, or batch validation of customer databases.

**How it works:** Cache hits are returned immediately. Only cache misses are sent to AI in a single prompt, minimizing API calls and latency. Each address counts as one request toward your monthly limit.

**Tip:** Include an `id` field (e.g. order number) in each address to easily map results back to your records. For more than 50 addresses, use the CSV batch upload at `/csv`.
DESC,
        security: [['bearerAuth' => []]],
        tags: ['Address Normalization'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['addresses'],
            properties: [
                new OA\Property(property: 'google_validate', type: 'boolean', description: 'Override Google Address Validation for all addresses in this batch. `true` = force, `false` = skip, omit = server default.', example: true, nullable: true),
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

        $googleValidate = isset($validated['google_validate']) ? (bool) $validated['google_validate'] : null;

        try {
            $result = $this->normalizer->normalizeBatch($inputs, $client, $googleValidate);

            return response()->json($result);
        } catch (NormalizationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'All AI providers are currently unavailable. Please try again later.',
            ], 503);
        }
    }

    #[OA\Post(
        path: '/api/v1/validate',
        summary: 'Validate an address via Google Address Validation API',
        description: <<<'DESC'
Validates an address directly through Google Address Validation API **without AI normalization**.

**When to use:**
- After a previous `/normalize` call (without Google validation) to verify the result
- To get geographic coordinates for an already-clean address
- To check address deliverability without running the full normalization pipeline

**What it returns:** geographic coordinates (lat/lng), validation granularity, quality flags, property type (residential/business), and a list of issues found.

**Note:** This endpoint requires Google Address Validation to be configured on the server (`GOOGLE_VALIDATION_API_KEY`). Returns `503` if not configured.
DESC,
        security: [['bearerAuth' => []]],
        tags: ['Address Validation'],
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ['country', 'city', 'address'],
            properties: [
                new OA\Property(property: 'country', type: 'string', description: 'ISO 3166-1 alpha-2 country code', example: 'PL', minLength: 2, maxLength: 2),
                new OA\Property(property: 'postal_code', type: 'string', description: 'Postal/ZIP code', example: '00-001', nullable: true),
                new OA\Property(property: 'city', type: 'string', description: 'City name', example: 'Warszawa', maxLength: 255),
                new OA\Property(property: 'address', type: 'string', description: 'Street address line', example: 'Marszałkowska 1/2', maxLength: 500),
            ],
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Address validated successfully',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', enum: ['ok'], example: 'ok'),
                new OA\Property(property: 'validation', ref: '#/components/schemas/GoogleValidationData'),
            ],
        ),
    )]
    #[OA\Response(response: 401, ref: '#/components/responses/Unauthorized')]
    #[OA\Response(
        response: 422,
        description: 'Validation error',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'message', type: 'string', example: 'The country field is required.'),
                new OA\Property(property: 'errors', type: 'object'),
            ],
        ),
    )]
    #[OA\Response(response: 429, ref: '#/components/responses/RateLimitExceeded')]
    #[OA\Response(
        response: 503,
        description: 'Google Address Validation is not configured or unavailable',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'status', type: 'string', example: 'error'),
                new OA\Property(property: 'message', type: 'string', example: 'Google Address Validation is not configured. Set GOOGLE_VALIDATION_API_KEY in server configuration.'),
            ],
        ),
    )]
    public function validate(ValidateAddressRequest $request): JsonResponse
    {
        if (! $this->googleValidator->hasApiKey()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Address Validation is not configured. Set GOOGLE_VALIDATION_API_KEY in server configuration.',
            ], 503);
        }

        $validated = $request->validated();

        $result = $this->googleValidator->validateRaw(
            countryCode: $validated['country'],
            postalCode: $validated['postal_code'] ?? null,
            city: $validated['city'],
            address: $validated['address'],
            force: true,
        );

        if (! $result) {
            return response()->json([
                'status' => 'error',
                'message' => 'Google Address Validation API returned no result. Please try again.',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'validation' => $result->toArray(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/status',
        summary: 'Health check and usage statistics',
        description: <<<'DESC'
Returns the current API client status.

**When to use:** To monitor your usage, check remaining quota before a large batch, or verify the API is operational.

**Returns:** client name, monthly limit, current usage, remaining requests, cache hit rate (0.0–1.0), and which AI provider is active (openai or anthropic).
DESC,
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
