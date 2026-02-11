<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackApiUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();

        if (! $client || ! $client->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'API client is deactivated.',
            ], 401);
        }

        // Calculate how many addresses this request will process
        $count = $this->getAddressCount($request);

        // Check monthly limit
        if ($client->current_month_usage + $count > $client->monthly_limit) {
            return response()->json([
                'status' => 'error',
                'message' => 'Monthly usage limit exceeded.',
                'limit' => $client->monthly_limit,
                'used' => $client->current_month_usage,
            ], 429);
        }

        $response = $next($request);

        // Increment usage only on successful requests
        if ($response->getStatusCode() === 200) {
            $client->incrementUsage($count);
        }

        return $response;
    }

    private function getAddressCount(Request $request): int
    {
        // Batch endpoint: count addresses in array
        if ($request->has('addresses') && is_array($request->input('addresses'))) {
            return count($request->input('addresses'));
        }

        // Single endpoint
        return 1;
    }
}
