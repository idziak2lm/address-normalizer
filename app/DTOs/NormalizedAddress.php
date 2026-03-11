<?php

namespace App\DTOs;

final readonly class NormalizedAddress
{
    public function __construct(
        public string $country_code,
        public ?string $region,
        public ?string $postal_code,
        public string $city,
        public ?string $street,
        public ?string $house_number,
        public ?string $apartment_number,
        public ?string $company_name,
        public string $formatted,
        public array $removed_noise,
        public float $confidence,
        public ?GoogleValidationResult $google_validation = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $googleValidation = null;
        if (isset($data['google_validation']) && is_array($data['google_validation'])) {
            $googleValidation = self::hydrateGoogleValidation($data['google_validation']);
        }

        return new self(
            country_code: $data['country_code'],
            region: $data['region'] ?? null,
            postal_code: $data['postal_code'] ?? null,
            city: $data['city'],
            street: $data['street'] ?? null,
            house_number: $data['house_number'] ?? null,
            apartment_number: $data['apartment_number'] ?? null,
            company_name: $data['company_name'] ?? null,
            formatted: $data['formatted'] ?? '',
            removed_noise: $data['removed_noise'] ?? [],
            confidence: (float) ($data['confidence'] ?? 0.0),
            google_validation: $googleValidation,
        );
    }

    public function toArray(): array
    {
        $data = [
            'country_code' => $this->country_code,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'street' => $this->street,
            'house_number' => $this->house_number,
            'apartment_number' => $this->apartment_number,
            'company_name' => $this->company_name,
            'formatted' => $this->formatted,
        ];

        if ($this->google_validation) {
            $data['google_validation'] = $this->google_validation->toArray();
        }

        return $data;
    }

    public function withConfidence(float $confidence): self
    {
        return new self(
            country_code: $this->country_code,
            region: $this->region,
            postal_code: $this->postal_code,
            city: $this->city,
            street: $this->street,
            house_number: $this->house_number,
            apartment_number: $this->apartment_number,
            company_name: $this->company_name,
            formatted: $this->formatted,
            removed_noise: $this->removed_noise,
            confidence: $confidence,
            google_validation: $this->google_validation,
        );
    }

    private static function hydrateGoogleValidation(array $data): GoogleValidationResult
    {
        return new GoogleValidationResult(
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            placeId: $data['place_id'] ?? null,
            validationGranularity: $data['validation_granularity'] ?? 'OTHER',
            geocodeGranularity: $data['geocode_granularity'] ?? 'OTHER',
            addressComplete: $data['address_complete'] ?? false,
            hasUnconfirmedComponents: $data['has_unconfirmed_components'] ?? false,
            hasInferredComponents: $data['has_inferred_components'] ?? false,
            hasReplacedComponents: $data['has_replaced_components'] ?? false,
            hasSpellCorrectedComponents: $data['has_spell_corrected_components'] ?? false,
            missingComponentTypes: $data['missing_component_types'] ?? [],
            unconfirmedComponentTypes: $data['unconfirmed_component_types'] ?? [],
            unresolvedTokens: $data['unresolved_tokens'] ?? [],
            formattedAddress: $data['formatted_address'] ?? null,
            correctedPostalCode: $data['corrected_postal_code'] ?? null,
            correctedCity: $data['corrected_city'] ?? null,
            correctedStreet: $data['corrected_street'] ?? null,
            isResidential: $data['is_residential'] ?? null,
            isBusiness: $data['is_business'] ?? null,
        );
    }
}
