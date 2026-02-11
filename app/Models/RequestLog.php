<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'api_client_id',
        'source',
        'provider',
        'raw_input',
        'normalized_output',
        'confidence',
        'processing_time_ms',
        'is_successful',
        'error_message',
        'country_code',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_input' => 'array',
            'normalized_output' => 'array',
            'confidence' => 'float',
            'processing_time_ms' => 'integer',
            'is_successful' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function apiClient(): BelongsTo
    {
        return $this->belongsTo(ApiClient::class);
    }
}
