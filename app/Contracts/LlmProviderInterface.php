<?php

namespace App\Contracts;

use App\DTOs\NormalizedAddress;
use App\DTOs\RawAddressInput;

interface LlmProviderInterface
{
    /**
     * Normalize a single address via AI.
     *
     * @throws \App\Exceptions\NormalizationException
     */
    public function normalize(RawAddressInput $input): NormalizedAddress;

    /**
     * Normalize a batch of addresses.
     * Default implementation: iterate one by one.
     *
     * @param  RawAddressInput[]  $inputs
     * @return NormalizedAddress[]
     */
    public function normalizeBatch(array $inputs): array;

    /**
     * Provider name for logs.
     */
    public function name(): string;
}
