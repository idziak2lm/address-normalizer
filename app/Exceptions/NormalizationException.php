<?php

namespace App\Exceptions;

use RuntimeException;

class NormalizationException extends RuntimeException
{
    public static function providerFailed(string $provider, string $reason): self
    {
        return new self("Provider [{$provider}] failed: {$reason}");
    }

    public static function allProvidersFailed(): self
    {
        return new self('All AI providers failed to normalize the address.');
    }
}
