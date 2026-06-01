<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'return_number', 'original_sale_id', 'branch_id', 'customer_id',
        'cashier_id', 'return_date', 'refund_amount', 'refund_method',
        'reason', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'return_date'   => 'date',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function originalSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public static function generateNumber(): string
    {
        $year  = now()->year;
        $count = static::whereYear('created_at', $year)->withTrashed()->count() + 1;
        return sprintf('RET-%s-%06d', $year, $count);
    }
}
