<?php

namespace App\Services;

use App\DTOs\NormalizedAddress;
use App\DTOs\RawAddressInput;
use Illuminate\Support\Facades\Redis;

class CacheManager
{
    public function lookup(RawAddressInput $input): ?NormalizedAddress
    {
        if (! config('normalizer.cache.enabled')) {
            return null;
        }

        $key = $this->generateKey($input);
        $cached = Redis::get($key);

        if ($cached === null) {
            return null;
        }

        $data = json_decode($cached, true);

        return $data ? NormalizedAddress::fromArray($data) : null;
    }

    public function store(RawAddressInput $input, NormalizedAddress $result): void
    {
        if (! config('normalizer.cache.enabled')) {
            return;
        }

        $key = $this->generateKey($input);
        $ttl = config('normalizer.cache.ttl_days', 30) * 86400;

        $data = array_merge($result->toArray(), [
            'removed_noise' => $result->removed_noise,
            'confidence' => $result->confidence,
        ]);

        Redis::setex($key, $ttl, json_encode($data));
    }

    public function generateKey(RawAddressInput $input): string
    {
        $prefix = config('normalizer.cache.prefix', 'addr:');

        // Sanitize: exclude full_name from cache key (GDPR)
        $raw = strtolower(trim(implode('|', [
            $input->country,
            $input->postal_code ?? '',
            $input->city,
            $input->address,
        ])));

        return $prefix . md5($raw);
    }
}
