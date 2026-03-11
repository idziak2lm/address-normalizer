<?php

namespace App\Services;

use App\DTOs\GoogleValidationResult;
use App\DTOs\NormalizedAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAddressValidationClient
{
    private const API_URL = 'https://addressvalidation.googleapis.com/v1:validateAddress';

    public function isEnabled(): bool
    {
        return (bool) config('normalizer.google_validation.enabled')
            && ! empty(config('normalizer.google_validation.api_key'));
    }

    /**
     * Validate and enrich a normalized address via Google Address Validation API.
     */
    public function validate(NormalizedAddress $address): ?GoogleValidationResult
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $url = self::API_URL . '?key=' . config('normalizer.google_validation.api_key');

            $response = Http::timeout(config('normalizer.google_validation.timeout', 5))
                ->post($url, [
                    'address' => $this->buildAddressPayload($address),
                ]);

            if (! $response->successful()) {
                Log::warning('Google Address Validation API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $response->json('result');

            if (! $result) {
                return null;
            }

            return GoogleValidationResult::fromApiResponse($result);
        } catch (\Throwable $e) {
            Log::warning('Google Address Validation API exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Validate a raw address (not yet normalized) via Google Address Validation API.
     */
    public function validateRaw(
        string $countryCode,
        ?string $postalCode,
        string $city,
        string $address,
    ): ?GoogleValidationResult {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $url = self::API_URL . '?key=' . config('normalizer.google_validation.api_key');

            $payload = array_filter([
                'regionCode' => $countryCode,
                'locality' => $city,
                'postalCode' => $postalCode,
                'addressLines' => [$address],
            ]);

            $response = Http::timeout(config('normalizer.google_validation.timeout', 5))
                ->post($url, ['address' => $payload]);

            if (! $response->successful()) {
                Log::warning('Google Address Validation API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $result = $response->json('result');

            return $result ? GoogleValidationResult::fromApiResponse($result) : null;
        } catch (\Throwable $e) {
            Log::warning('Google Address Validation API exception', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function buildAddressPayload(NormalizedAddress $address): array
    {
        $addressLines = [];

        $streetLine = $address->street ?? '';
        if ($address->house_number) {
            $streetLine .= ' ' . $address->house_number;
        }
        if ($address->apartment_number) {
            $streetLine .= '/' . $address->apartment_number;
        }
        $streetLine = trim($streetLine);

        if ($streetLine !== '') {
            $addressLines[] = $streetLine;
        }

        return array_filter([
            'regionCode' => $address->country_code,
            'locality' => $address->city,
            'postalCode' => $address->postal_code,
            'administrativeArea' => $address->region,
            'addressLines' => $addressLines ?: null,
        ]);
    }

    /**
     * Apply Google validation corrections to the normalized address.
     */
    public function applyCorrections(NormalizedAddress $address, GoogleValidationResult $validation): NormalizedAddress
    {
        $postalCode = $validation->correctedPostalCode ?? $address->postal_code;
        $city = $validation->correctedCity ?? $address->city;
        $street = $validation->correctedStreet ?? $address->street;

        $confidenceAdjustment = $validation->confidenceAdjustment();
        $newConfidence = max(0.0, min(1.0, $address->confidence + $confidenceAdjustment));

        return new NormalizedAddress(
            country_code: $address->country_code,
            region: $address->region,
            postal_code: $postalCode,
            city: $city,
            street: $street,
            house_number: $address->house_number,
            apartment_number: $address->apartment_number,
            company_name: $address->company_name,
            formatted: $address->formatted,
            removed_noise: $address->removed_noise,
            confidence: round($newConfidence, 2),
            google_validation: $validation,
        );
    }
}
