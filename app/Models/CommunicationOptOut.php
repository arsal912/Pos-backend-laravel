<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationOptOut extends Model
{
    protected $fillable = [
        'channel', 'recipient', 'customer_id', 'reason', 'opted_out_at',
    ];

    protected function casts(): array
    {
        return ['opted_out_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Normalise phone for consistent lookup (strip spaces/dashes). */
    public static function normalise(string $recipient, string $channel): string
    {
        if ($channel === 'email') {
            return strtolower(trim($recipient));
        }
        // Phone: keep + prefix, strip non-digits otherwise
        return preg_replace('/[^\d+]/', '', trim($recipient));
    }

    public static function isOptedOut(string $channel, string $recipient): bool
    {
        return static::where('channel', $channel)
            ->where('recipient', static::normalise($recipient, $channel))
            ->exists();
    }
}
