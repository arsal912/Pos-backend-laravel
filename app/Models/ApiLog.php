<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiLog extends Model
{
    use HasFactory;

    protected $table = 'api_loggings';

    protected $fillable = [
        'user_id',
        'store_id',
        'method',
        'endpoint',
        'route_name',
        'request_headers',
        'request_payload',
        'response_status',
        'response_body',
        'ip_address',
        'user_agent',
        'duration_ms',
        'exception',
        'stack_trace',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_payload' => 'array',
            'response_body' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Scope: only error responses.
     */
    public function scopeErrors($query)
    {
        return $query->where('response_status', '>=', 400);
    }

    /**
     * Scope: slow requests (> 1 second).
     */
    public function scopeSlow($query, int $thresholdMs = 1000)
    {
        return $query->where('duration_ms', '>', $thresholdMs);
    }
}
