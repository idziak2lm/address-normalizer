<?php

namespace App\Services;

use App\DTOs\GoogleValidationResult;
use App\DTOs\NormalizedAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleAddressValidationClient
{
    private const API_URL = 'https://addressvalidation.googleapis.com/v1:validateAddress';
    private const PLACES_AUTOCOMPLETE_URL = 'https://places.googleapis.com/v1/places:autocomplete';
    private const PLACES_DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    public function isEnabled(): bool
    {
        return (bool) config('normalizer.google_validation.enabled')
            && ! empty(config('normalizer.google_validation.api_key'));
    }

    /**
     * Check if the API key is configured (regardless of enabled flag).
     */
    public function hasApiKey(): bool
    {
        return ! empty(config('normalizer.google_validation.api_key'));
    }

    /**
     * Validate and enrich a normalized address via Google Address Validation API.
     *
     * @param  bool  $force  Bypass the enabled check (still requires API key)
     */
    public function validate(NormalizedAddress $address, bool $force = false): ?GoogleValidationResult
    {
        if (! $force && ! $this->isEnabled()) {
            return null;
        }

        if (! $this->hasApiKey()) {
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

            Log::debug('Google Address Validation raw response', [
                'payload' => $this->buildAddressPayload($address),
                'result' => $result,
            ]);

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
     *
     * @param  bool  $force  Bypass the enabled check (still requires API key)
     */
    public function validateRaw(
        string $countryCode,
        ?string $postalCode,
        string $city,
        string $address,
        bool $force = false,
    ): ?GoogleValidationResult {
        if (! $force && ! $this->isEnabled()) {
            return null;
        }

        if (! $this->hasApiKey()) {
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

    // Countries where house number comes before street name (e.g. "15 Rue de Rivoli")
    private const NUMBER_BEFORE_STREET = ['FR', 'BE', 'LU', 'MC'];

    private function buildAddressPayload(NormalizedAddress $address): array
    {
        // Build a full formatted address line for Google to parse.
        // Google works best with a complete, human-readable address string.
        $parts = [];

        $number = $address->house_number ?? '';
        $street = $address->street ?? '';
        $country = strtoupper($address->country_code ?? '');

        if (in_array($country, self::NUMBER_BEFORE_STREET, true)) {
            // FR/BE/LU/MC: "15 Rue de Rivoli"
            $streetLine = trim($number . ' ' . $street);
        } else {
            // Most countries: "Srebrzyńska 8D"
            $streetLine = trim($street . ' ' . $number);
        }

        if ($streetLine !== '') {
            $parts[] = $streetLine;
        }

        // Apartment as separate part so Google doesn't merge it with street_number
        if ($address->apartment_number) {
            $parts[] = 'Apt ' . $address->apartment_number;
        }

        if ($address->postal_code && $address->city) {
            $parts[] = $address->postal_code . ' ' . $address->city;
        } elseif ($address->city) {
            $parts[] = $address->city;
        }

        return [
            'regionCode' => $address->country_code,
            'addressLines' => [implode(', ', $parts)],
        ];
    }

    /**
     * Resolve an unconfirmed route using Places Autocomplete + Place Details.
     *
     * When Address Validation returns UNCONFIRMED_BUT_PLAUSIBLE for a route,
     * use Places Autocomplete to find the correct street name, then re-validate.
     *
     * @return array{validation: GoogleValidationResult, street: string}|null
     */
    public function resolveUnconfirmedRoute(NormalizedAddress $address, GoogleValidationResult $validation): ?array
    {
        if (! $validation->hasUnconfirmedRoute()) {
            return null;
        }

        if (! config('normalizer.google_validation.places_resolve_enabled', true)) {
            return null;
        }

        $apiKey = config('normalizer.google_validation.api_key');
        if (! $apiKey) {
            return null;
        }

        // Try multiple query strategies — full context first (works best for
        // typos like "Nwoa" → "Nowa"), then progressively shorter as fallback.
        $street = $address->street ?? '';
        $houseNumber = $address->house_number ?? '';
        $postalCode = $address->postal_code ?? '';
        $city = $address->city ?? '';
        $streetStem = $this->stripStreetSuffix($street);

        $queries = array_values(array_unique(array_filter([
            // 1. Full address: "Nwoa 11, 55010 Święta Katarzyna"
            trim($street . ' ' . $houseNumber . ', ' . $postalCode . ' ' . $city),
            // 2. Street + house number + city (no postal code)
            trim($street . ' ' . $houseNumber . ', ' . $city),
            // 3. Street stem + city: "Mitteihäusser Rethem"
            trim($streetStem . ' ' . $city),
        ])));

        $placeId = null;
        foreach ($queries as $query) {
            $placeId = $this->placesAutocomplete($query, $address->country_code, $apiKey);

            if ($placeId) {
                break;
            }
        }

        if (! $placeId) {
            return null;
        }

        $correctedStreet = $this->placeDetailsStreet($placeId, $apiKey);
        if (! $correctedStreet) {
            return null;
        }

        // Skip if Places returned the same street name (no improvement)
        if (mb_strtolower($correctedStreet) === mb_strtolower($address->street ?? '')) {
            return null;
        }

        // Verify similarity — reject if the resolved street is too different from the original.
        // This prevents Places from returning a random street in the same city.
        $similarity = $this->streetSimilarity($address->street ?? '', $correctedStreet);
        if ($similarity < 0.5) {
            Log::debug('Places resolve: rejected — street too different', [
                'original' => $address->street,
                'resolved' => $correctedStreet,
                'similarity' => $similarity,
            ]);

            return null;
        }

        Log::info('Places API resolved unconfirmed route', [
            'original' => $address->street,
            'resolved' => $correctedStreet,
            'similarity' => $similarity,
            'place_id' => $placeId,
        ]);

        // Re-validate with corrected street via Address Validation
        $correctedAddress = new NormalizedAddress(
            country_code: $address->country_code,
            region: $address->region,
            postal_code: $address->postal_code,
            city: $address->city,
            street: $correctedStreet,
            house_number: $address->house_number,
            apartment_number: $address->apartment_number,
            company_name: $address->company_name,
            formatted: $address->formatted,
            removed_noise: $address->removed_noise,
            confidence: $address->confidence,
        );

        $revalidation = $this->validate($correctedAddress, force: true);
        if (! $revalidation) {
            return null;
        }

        // Only use the re-validated result if it's actually better
        if ($revalidation->hasUnconfirmedRoute()) {
            return null;
        }

        return [
            'validation' => $revalidation,
            'street' => $correctedStreet,
        ];
    }

    /**
     * Call Google Places Autocomplete (New) to find a place matching the query.
     */
    private function placesAutocomplete(string $query, string $countryCode, string $apiKey): ?string
    {
        try {
            $timeout = config('normalizer.google_validation.timeout', 5);

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                ])
                ->post(self::PLACES_AUTOCOMPLETE_URL, [
                    'input' => $query,
                    'includedRegionCodes' => [strtolower($countryCode)],
                    'languageCode' => $this->countryToLanguage($countryCode),
                ]);

            if (! $response->successful()) {
                Log::warning('Places Autocomplete API error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $suggestions = $response->json('suggestions', []);

            // Find first suggestion that is an address, not a locality/region
            $placeId = null;
            foreach ($suggestions as $suggestion) {
                $prediction = $suggestion['placePrediction'] ?? null;
                if (! $prediction) {
                    continue;
                }

                $types = $prediction['types'] ?? [];

                // Skip pure locality/region matches — we need a street-level result
                if (in_array('locality', $types) && ! in_array('route', $types) && ! in_array('street_address', $types)) {
                    continue;
                }

                $placeId = $prediction['placeId'] ?? null;

                break;
            }

            if (! $placeId) {
                return null;
            }

            return $placeId;
        } catch (\Throwable $e) {
            Log::warning('Places Autocomplete exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Strip common street type suffixes to get the name stem.
     * E.g. "Mittelhäusserstraße" → "Mittelhäusser", "ul. Długa" → "Długa"
     */
    private function stripStreetSuffix(string $street): string
    {
        $street = trim($street);

        // German/European suffixes — also handle standalone words like " Straße"
        // Use case-insensitive regex to catch "straße", "Straße", "STRASSE", etc.
        $pattern = '/[\s-]*(straße|strasse|str\.?|gasse|weg|platz|allee|ring|damm|ufer|steig|road|street|lane|avenue|rue|via)$/iu';
        $stripped = preg_replace($pattern, '', $street);

        if ($stripped !== null && $stripped !== '' && $stripped !== $street) {
            return rtrim($stripped);
        }

        // Prefixes (Polish, etc.)
        $prefixPattern = '/^(ul\.?|ulica|al\.?|aleja|os\.?|osiedle|pl\.?|plac)\s+/iu';
        $stripped = preg_replace($prefixPattern, '', $street);

        return $stripped !== null && $stripped !== '' ? $stripped : $street;
    }

    /**
     * Calculate similarity between two street names (0.0 = completely different, 1.0 = identical).
     *
     * Normalizes common suffixes (straße/str/strasse, gasse, weg, etc.) before comparing,
     * so "Mittelhäusserstraße" vs "Mittelhäusserstr" scores 1.0.
     */
    private function streetSimilarity(string $original, string $resolved): float
    {
        $normalize = function (string $street): string {
            $street = mb_strtolower(trim($street));

            // Remove common street type suffixes to compare just the name part
            $suffixes = [
                'straße', 'strasse', 'str.', 'str',
                'gasse', 'weg', 'platz', 'allee', 'ring', 'damm', 'ufer', 'steig',
                'road', 'street', 'st.', 'avenue', 'ave', 'lane', 'drive',
                'rue', 'via', 'ulica', 'ul.',
            ];

            foreach ($suffixes as $suffix) {
                if (str_ends_with($street, $suffix)) {
                    $street = rtrim(mb_substr($street, 0, -mb_strlen($suffix)));

                    break;
                }
            }

            return $street;
        };

        $a = $normalize($original);
        $b = $normalize($resolved);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(mb_strlen($a), mb_strlen($b));
        $distance = levenshtein($a, $b);

        return max(0.0, 1.0 - ($distance / $maxLen));
    }

    private function countryToLanguage(string $countryCode): string
    {
        return match (strtoupper($countryCode)) {
            'AT', 'DE', 'CH', 'LI' => 'de',
            'BE' => 'nl',
            'GB', 'IE' => 'en',
            'CY' => 'el',
            default => strtolower($countryCode),
        };
    }

    /**
     * Call Google Place Details (New) to get the street name from address components.
     */
    private function placeDetailsStreet(string $placeId, string $apiKey): ?string
    {
        try {
            $timeout = config('normalizer.google_validation.timeout', 5);

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => 'addressComponents',
                ])
                ->get(self::PLACES_DETAILS_URL . $placeId);

            if (! $response->successful()) {
                Log::warning('Place Details API error', [
                    'status' => $response->status(),
                    'place_id' => $placeId,
                ]);

                return null;
            }

            $components = $response->json('addressComponents', []);

            foreach ($components as $component) {
                $types = $component['types'] ?? [];
                if (in_array('route', $types)) {
                    return $component['longText'] ?? $component['shortText'] ?? null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('Place Details exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Apply Google validation corrections to the normalized address.
     */
    public function applyCorrections(NormalizedAddress $address, GoogleValidationResult $validation): NormalizedAddress
    {
        // Attempt to resolve unconfirmed route via Places API
        $resolvedStreet = null;
        $resolved = $this->resolveUnconfirmedRoute($address, $validation);
        if ($resolved) {
            $validation = $resolved['validation']->withPlacesResolvedStreet($resolved['street']);
            $resolvedStreet = $resolved['street'];
        }

        $postalCode = $validation->correctedPostalCode ?? $address->postal_code;
        $city = $validation->correctedCity ?? $address->city;
        $street = $resolvedStreet ?? $validation->correctedStreet ?? $address->street;

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
