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

class AddressController extends Controller
{
    public function __construct(
        private readonly AddressNormalizer $normalizer,
    ) {}

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
