<?php

namespace App\Services\Parsers;

use App\Contracts\AddressParserInterface;
use App\DTOs\RawAddressInput;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LibpostalClient implements AddressParserInterface
{
    private string $url;
    private int $timeout;

    public function __construct()
    {
        $this->url = config('normalizer.libpostal.url');
        $this->timeout = config('normalizer.libpostal.timeout');
    }

    public function parse(RawAddressInput $input): array
    {
        $fullAddress = implode(', ', array_filter([
            $input->address,
            $input->city,
            $input->postal_code,
            $input->country,
        ]));

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->url}/parse", [
                    'address' => $fullAddress,
                ]);

            if ($response->successful()) {
                return $response->json() ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Libpostal parse failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    public function isAvailable(): bool
    {
        if (! config('normalizer.libpostal.enabled')) {
            return false;
        }

        try {
            $response = Http::timeout(2)->get("{$this->url}/health");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
