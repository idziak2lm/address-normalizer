<?php

namespace App\DTOs;

final readonly class GoogleValidationResult
{
    public function __construct(
        public ?float $latitude,
        public ?float $longitude,
        public ?string $placeId,
        public string $validationGranularity,
        public string $geocodeGranularity,
        public bool $addressComplete,
        public bool $hasUnconfirmedComponents,
        public bool $hasInferredComponents,
        public bool $hasReplacedComponents,
        public bool $hasSpellCorrectedComponents,
        public array $missingComponentTypes,
        public array $unconfirmedComponentTypes,
        public array $unresolvedTokens,
        public ?string $formattedAddress,
        public ?string $correctedPostalCode,
        public ?string $correctedCity,
        public ?string $correctedStreet,
        public ?bool $isResidential,
        public ?bool $isBusiness,
    ) {}

    public static function fromApiResponse(array $result): self
    {
        $verdict = $result['verdict'] ?? [];
        $geocode = $result['geocode'] ?? [];
        $address = $result['address'] ?? [];
        $metadata = $result['metadata'] ?? [];
        $postalAddress = $address['postalAddress'] ?? [];

        $location = $geocode['location'] ?? [];

        // Extract corrected values from addressComponents
        $correctedPostalCode = null;
        $correctedCity = null;
        $correctedStreet = null;

        foreach ($address['addressComponents'] ?? [] as $component) {
            $type = $component['componentType'] ?? '';
            $text = $component['componentName']['text'] ?? null;
            $replaced = $component['replaced'] ?? false;
            $spellCorrected = $component['spellCorrected'] ?? false;

            if (($replaced || $spellCorrected) && $text) {
                match ($type) {
                    'postal_code' => $correctedPostalCode = $text,
                    'locality' => $correctedCity = $text,
                    'route' => $correctedStreet = $text,
                    default => null,
                };
            }
        }

        return new self(
            latitude: isset($location['latitude']) ? (float) $location['latitude'] : null,
            longitude: isset($location['longitude']) ? (float) $location['longitude'] : null,
            placeId: $geocode['placeId'] ?? null,
            validationGranularity: $verdict['validationGranularity'] ?? 'OTHER',
            geocodeGranularity: $verdict['geocodeGranularity'] ?? 'OTHER',
            addressComplete: $verdict['addressComplete'] ?? false,
            hasUnconfirmedComponents: $verdict['hasUnconfirmedComponents'] ?? false,
            hasInferredComponents: $verdict['hasInferredComponents'] ?? false,
            hasReplacedComponents: $verdict['hasReplacedComponents'] ?? false,
            hasSpellCorrectedComponents: $verdict['hasSpellCorrectedComponents'] ?? false,
            missingComponentTypes: $address['missingComponentTypes'] ?? [],
            unconfirmedComponentTypes: $address['unconfirmedComponentTypes'] ?? [],
            unresolvedTokens: $address['unresolvedTokens'] ?? [],
            formattedAddress: $address['formattedAddress'] ?? null,
            correctedPostalCode: $correctedPostalCode,
            correctedCity: $correctedCity,
            correctedStreet: $correctedStreet,
            isResidential: $metadata['residential'] ?? null,
            isBusiness: $metadata['business'] ?? null,
        );
    }

    /**
     * Calculate a confidence adjustment based on Google's validation verdict.
     */
    public function confidenceAdjustment(): float
    {
        $adjustment = match ($this->validationGranularity) {
            'SUB_PREMISE' => 0.10,
            'PREMISE' => 0.05,
            'PREMISE_PROXIMITY' => -0.05,
            'BLOCK', 'ROUTE' => -0.15,
            'OTHER' => -0.25,
            default => 0.0,
        };

        if ($this->addressComplete) {
            $adjustment += 0.05;
        }

        if ($this->hasUnconfirmedComponents) {
            $adjustment -= 0.10;
        }

        if ($this->hasInferredComponents) {
            $adjustment -= 0.05;
        }

        return $adjustment;
    }

    /**
     * Build a list of human-readable validation issues.
     */
    public function issues(): array
    {
        $issues = [];

        if (! $this->addressComplete) {
            $issues[] = 'Address is incomplete';
        }

        if ($this->hasUnconfirmedComponents) {
            $issues[] = 'Some address components could not be confirmed: ' . implode(', ', $this->unconfirmedComponentTypes);
        }

        if ($this->hasInferredComponents) {
            $issues[] = 'Some address components were inferred (not present in input)';
        }

        if ($this->hasReplacedComponents) {
            $issues[] = 'Some address components were replaced/corrected';
        }

        if ($this->hasSpellCorrectedComponents) {
            $issues[] = 'Spelling corrections were applied';
        }

        if (! empty($this->missingComponentTypes)) {
            $issues[] = 'Missing components: ' . implode(', ', $this->missingComponentTypes);
        }

        if (! empty($this->unresolvedTokens)) {
            $issues[] = 'Unresolved tokens: ' . implode(', ', $this->unresolvedTokens);
        }

        return $issues;
    }

    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'place_id' => $this->placeId,
            'validation_granularity' => $this->validationGranularity,
            'geocode_granularity' => $this->geocodeGranularity,
            'address_complete' => $this->addressComplete,
            'has_unconfirmed_components' => $this->hasUnconfirmedComponents,
            'has_inferred_components' => $this->hasInferredComponents,
            'has_replaced_components' => $this->hasReplacedComponents,
            'has_spell_corrected_components' => $this->hasSpellCorrectedComponents,
            'is_residential' => $this->isResidential,
            'is_business' => $this->isBusiness,
            'issues' => $this->issues(),
        ];
    }
}
