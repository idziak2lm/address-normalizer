<?php

namespace App\Services;

use App\DTOs\NormalizedAddress;
use App\Enums\CountryCode;

class PostValidator
{
    public function __construct(
        private readonly StreetParser $streetParser = new StreetParser,
    ) {}

    /**
     * Validate AI results and adjust confidence accordingly.
     */
    public function validate(NormalizedAddress $address, ?string $rawAddress = null): NormalizedAddress
    {
        if (! config('normalizer.validate_postal_codes')) {
            return $address;
        }

        $confidencePenalty = 0.0;

        // Validate country code
        if (! CountryCode::isValid($address->country_code)) {
            $confidencePenalty += 0.2;
        }

        // Validate postal code format
        if ($address->postal_code && ! $this->isValidPostalCode($address->country_code, $address->postal_code)) {
            $confidencePenalty += 0.2;
        }

        // Sanity check: house_number should not look like a postal code
        if ($address->house_number && $this->looksLikePostalCode($address->house_number)) {
            $confidencePenalty += 0.2;
        }

        // Cross-check with regex parse if raw address is available
        if ($rawAddress && $address->house_number) {
            $confidencePenalty += $this->crossCheckWithRegex($address, $rawAddress);
        }

        if ($confidencePenalty > 0) {
            $newConfidence = max(0.0, $address->confidence - $confidencePenalty);

            return $address->withConfidence(round($newConfidence, 2));
        }

        return $address;
    }

    public function isValidPostalCode(string $countryCode, string $postalCode): bool
    {
        $country = CountryCode::tryFrom(strtoupper($countryCode));
        if (! $country) {
            return false;
        }

        $pattern = $country->postalCodePattern();
        if (! $pattern) {
            return true;
        }

        return (bool) preg_match($pattern, trim($postalCode));
    }

    private function looksLikePostalCode(string $houseNumber): bool
    {
        // A 5-digit number is likely a postal code, not a house number
        return (bool) preg_match('/^\d{5,}$/', trim($houseNumber));
    }

    /**
     * Cross-check AI result with regex parse. Returns additional penalty.
     */
    private function crossCheckWithRegex(NormalizedAddress $address, string $rawAddress): float
    {
        $regexResult = $this->streetParser->parse($address->country_code, $rawAddress);

        if (! $regexResult) {
            return 0.0;
        }

        $penalty = 0.0;

        // Normalize for comparison (strip spaces, lowercase)
        $aiNumber = strtolower(preg_replace('/\s+/', '', $address->house_number ?? ''));
        $regexNumber = strtolower(preg_replace('/\s+/', '', $regexResult['house_number']));

        // If regex and AI disagree on house number, something might be off
        if ($aiNumber !== '' && $regexNumber !== '' && $aiNumber !== $regexNumber) {
            $penalty += 0.1;
        }

        return $penalty;
    }
}
