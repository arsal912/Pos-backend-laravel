<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform-wide receipt footer — set only by the super admin, applied to
 * every store's receipts (thermal, A4, and PDF). Store owners can see it
 * in their receipt preview but cannot edit or remove it.
 */
class PlatformReceiptSetting extends Model
{
    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'is_enabled',
        'footer_text',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Get the singleton settings record (creates if missing).
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['is_enabled' => true]);
    }
}
