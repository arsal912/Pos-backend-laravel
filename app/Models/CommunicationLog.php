<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationLog extends Model
{
    protected $fillable = [
        'customer_id', 'recipient', 'channel', 'type',
        'subject', 'body', 'status', 'provider',
        'provider_response', 'sent_at', 'error_message',
        'sent_by', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'provider_response' => 'array',
            'sent_at'           => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
