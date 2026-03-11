<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CsvSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $clientId = session('api_client_id');

        if (! $clientId) {
            return redirect()->route('csv.login');
        }

        $client = ApiClient::find($clientId);

        if (! $client || ! $client->is_active) {
            session()->forget('api_client_id');

            return redirect()->route('csv.login')
                ->with('error', 'Session expired or client deactivated.');
        }

        $request->setUserResolver(fn () => $client);

        return $next($request);
    }
}
