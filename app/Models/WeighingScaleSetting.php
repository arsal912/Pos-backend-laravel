<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-store weighing-scale preferences. Tenant-scoped singleton (one row
 * per store database), mirroring PlatformReceiptSetting::current().
 *
 * connection_mode is "manual" only for now — there is no hardware/serial
 * integration in this codebase. Do not add one here without also removing
 * this comment and the validation guard in WeighingScaleSettingController.
 */
class WeighingScaleSetting extends Model
{
    protected $fillable = [
        'default_weight_unit',
        'connection_mode',
    ];

    /**
     * Get the singleton settings record for the current store (creates if missing).
     */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'default_weight_unit' => 'kg',
            'connection_mode'      => 'manual',
        ]);
    }
}
