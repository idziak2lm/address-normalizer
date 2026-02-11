<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApiClient extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $fillable = [
        'name',
        'api_key',
        'api_key_plain',
        'monthly_limit',
        'current_month_usage',
        'is_active',
        'preferred_provider',
        'settings',
    ];

    protected $hidden = [
        'api_key',
        'api_key_plain',
    ];

    protected function casts(): array
    {
        return [
            'monthly_limit' => 'integer',
            'current_month_usage' => 'integer',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function requestLogs(): HasMany
    {
        return $this->hasMany(RequestLog::class);
    }

    public function hasReachedLimit(): bool
    {
        return $this->current_month_usage >= $this->monthly_limit;
    }

    public function remainingQuota(): int
    {
        return max(0, $this->monthly_limit - $this->current_month_usage);
    }

    public function incrementUsage(int $count = 1): void
    {
        $this->increment('current_month_usage', $count);
    }
}
