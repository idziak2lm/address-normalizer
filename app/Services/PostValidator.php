<?php

namespace App\Services;

use App\DTOs\NormalizedAddress;
use App\Enums\CountryCode;

class PostValidator
{
    /**
     * Validate AI results and adjust confidence accordingly.
     */
    public function validate(NormalizedAddress $address): NormalizedAddress
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
}
