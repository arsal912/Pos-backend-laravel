<?php

namespace App\Models;

use App\Traits\HasCreatedUpdatedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use SoftDeletes, HasCreatedUpdatedBy;

    protected $fillable = [
        'po_number', 'supplier_id', 'branch_id', 'order_date',
        'expected_delivery_date', 'status',
        'subtotal', 'tax_amount', 'discount_amount', 'total',
        'notes', 'terms', 'created_by', 'updated_by', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date'               => 'date',
            'expected_delivery_date'   => 'date',
            'subtotal'                 => 'decimal:2',
            'tax_amount'               => 'decimal:2',
            'discount_amount'          => 'decimal:2',
            'total'                    => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function grns(): HasMany
    {
        return $this->hasMany(Grn::class);
    }

    public function recalculateTotals(): void
    {
        $sub  = $this->items()->sum('subtotal');
        $tax  = $this->items()->selectRaw('SUM(subtotal * tax_rate / 100) as tax')->value('tax') ?? 0;
        $disc = $this->items()->selectRaw('SUM(subtotal * discount / 100) as disc')->value('disc') ?? 0;

        $this->update([
            'subtotal'        => $sub,
            'tax_amount'      => $tax,
            'discount_amount' => $disc,
            'total'           => $sub + $tax - $disc,
        ]);
    }
}
