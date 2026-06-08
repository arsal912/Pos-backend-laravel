<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationLog extends Model
{
    protected $fillable = [
        // Core
        'customer_id', 'recipient', 'channel', 'type',
        'subject', 'body', 'status', 'provider',
        'provider_response', 'sent_at', 'error_message',
        'sent_by', 'reference_type', 'reference_id',
        // Phase 5 — delivery tracking
        'provider_message_id',
        'delivered_at', 'opened_at', 'clicked_at',
        'cost',
        'retry_count', 'next_retry_at',
        'campaign_id',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'sent_at'           => 'datetime',
            'delivered_at'      => 'datetime',
            'opened_at'         => 'datetime',
            'clicked_at'        => 'datetime',
            'next_retry_at'     => 'datetime',
            'cost'              => 'decimal:4',
            'retry_count'       => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
