<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stored in the CENTRAL database (pos_system), not tenant DB.
 * Explicit connection ensures this works from within tenant route context.
 */
class PosDevice extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'store_id',
        'device_uuid',
        'device_name',
        'user_agent',
        'fingerprint',
        'registered_by',
        'last_seen_at',
        'last_sync_at',
        'pending_sales_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /** Human-readable "online/offline" status based on last_seen_at. */
    public function getStatusAttribute(): string
    {
        if (! $this->last_seen_at) return 'never_seen';
        return $this->last_seen_at->gt(now()->subMinutes(10)) ? 'online' : 'offline';
    }
}
