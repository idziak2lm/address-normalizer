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
    ) {}

    public static function fromArray(array $data): self
    {
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
        );
    }

    public function toArray(): array
    {
        return [
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
        );
    }
}
