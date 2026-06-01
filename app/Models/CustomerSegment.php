<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSegment extends Model
{
    protected $fillable = [
        'name', 'description', 'rules',
        'customer_count_cached', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rules'                  => 'array',
            'is_active'              => 'boolean',
            'customer_count_cached'  => 'integer',
        ];
    }

    /** Run the saved rules against the customers table and return the matching query. */
    public function applyRules($query = null)
    {
        $query = $query ?? Customer::query();

        foreach ($this->rules ?? [] as $rule) {
            $field = $rule['field'] ?? null;
            $op    = $rule['op']    ?? '=';
            $value = $rule['value'] ?? null;

            if (! $field) continue;

            match ($field) {
                'total_purchases_count',
                'lifetime_value',
                'loyalty_points_balance',
                'outstanding_balance'
                    => $query->where($field, $op, $value),

                'last_purchase_at' => match ($op) {
                    'within_days'  => $query->where('last_purchase_at', '>=', now()->subDays((int) $value)),
                    'before_days'  => $query->where('last_purchase_at', '<=', now()->subDays((int) $value)),
                    default        => $query->whereDate('last_purchase_at', $op, $value),
                },

                'customer_group_id'
                    => $query->where('customer_group_id', $value),

                'tags'
                    => $query->whereJsonContains('tags', $value),

                'birthday_within_days' => $query->whereRaw(
                    'DAYOFYEAR(date_of_birth) BETWEEN DAYOFYEAR(NOW()) AND DAYOFYEAR(NOW()) + ?',
                    [(int) $value]
                ),

                'created_within_days'
                    => $query->where('created_at', '>=', now()->subDays((int) $value)),

                default => null,
            };
        }

        return $query;
    }
}
