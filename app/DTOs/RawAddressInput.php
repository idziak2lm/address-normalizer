<?php

namespace App\DTOs;

final readonly class RawAddressInput
{
    public function __construct(
        public string $country,
        public string $city,
        public string $address,
        public ?string $postal_code = null,
        public ?string $full_name = null,
        public ?string $id = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            country: $data['country'],
            city: $data['city'],
            address: $data['address'],
            postal_code: $data['postal_code'] ?? null,
            full_name: $data['full_name'] ?? null,
            id: $data['id'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'country' => $this->country,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'address' => $this->address,
            'full_name' => $this->full_name,
        ];
    }
}
