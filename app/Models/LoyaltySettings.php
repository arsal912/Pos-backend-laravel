<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltySettings extends Model
{
    protected $table = 'loyalty_settings';

    protected $fillable = [
        'is_enabled', 'points_per_currency_unit', 'redemption_value',
        'minimum_points_to_redeem', 'maximum_redemption_per_sale',
        'points_expiry_days', 'earn_on_discounted_sales', 'earn_on_tax',
        'welcome_bonus_points', 'birthday_bonus_points', 'referral_bonus_points',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled'                  => 'boolean',
            'points_per_currency_unit'    => 'decimal:4',
            'redemption_value'            => 'decimal:4',
            'minimum_points_to_redeem'    => 'decimal:2',
            'maximum_redemption_per_sale' => 'decimal:2',
            'points_expiry_days'          => 'integer',
            'earn_on_discounted_sales'    => 'boolean',
            'earn_on_tax'                 => 'boolean',
            'welcome_bonus_points'        => 'decimal:2',
            'birthday_bonus_points'       => 'decimal:2',
            'referral_bonus_points'       => 'decimal:2',
        ];
    }

    /** Get the single settings row, creating defaults if absent. */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'is_enabled'               => true,
                'points_per_currency_unit' => 1.0,
                'redemption_value'         => 1.0,
            ]
        );
    }
}
