<?php

namespace App\Contracts;

use App\DTOs\RawAddressInput;

interface AddressParserInterface
{
    /**
     * Pre-parse an address before sending to AI.
     * Returns parsed fields as an associative array.
     */
    public function parse(RawAddressInput $input): array;

    /**
     * Check if the parser service is available.
     */
    public function isAvailable(): bool;
}
