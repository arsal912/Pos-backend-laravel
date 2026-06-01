<?php

namespace App\Models;

use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use SoftDeletes, HasCreatedUpdatedBy;

    protected $fillable = [
        'code', 'name', 'email', 'phone', 'company', 'tax_number',
        'billing_address', 'shipping_address', 'city', 'country',
        'date_of_birth', 'gender', 'opening_balance', 'credit_limit',
        'notes', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'   => 'date',
            'opening_balance' => 'decimal:2',
            'credit_limit'    => 'decimal:2',
            'is_active'       => 'boolean',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        if (mb_strlen($term) >= 3) {
            return $query->whereRaw(
                'MATCH(name, phone, email) AGAINST(? IN BOOLEAN MODE)',
                [$term . '*']
            );
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
              ->orWhere('phone', 'like', "%{$term}%")
              ->orWhere('email', 'like', "%{$term}%")
              ->orWhere('code', 'like', "%{$term}%");
        });
    }

    public static function generateCode(): string
    {
        $count = static::withTrashed()->count() + 1;
        return sprintf('CUS-%06d', $count);
    }
}
