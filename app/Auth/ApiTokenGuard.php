<?php

namespace App\Auth;

use App\Models\ApiClient;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

class ApiTokenGuard implements Guard
{
    private ?Authenticatable $user = null;

    public function __construct(
        private readonly Request $request,
    ) {}

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function user(): ?Authenticatable
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->request->bearerToken();

        if (! $token) {
            return null;
        }

        $hash = hash('sha256', $token);

        $this->user = ApiClient::where('api_key', $hash)
            ->where('is_active', true)
            ->first();

        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        if (! isset($credentials['api_key'])) {
            return false;
        }

        $hash = hash('sha256', $credentials['api_key']);

        return ApiClient::where('api_key', $hash)
            ->where('is_active', true)
            ->exists();
    }

    public function hasUser(): bool
    {
        return $this->user !== null;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;

        return $this;
    }
}
