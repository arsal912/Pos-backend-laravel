<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends Model
{
    protected $fillable = [
        'branch_id', 'reason', 'notes',
        'created_by', 'approved_by', 'approved_at', 'status',
    ];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockAdjustmentItem::class);
    }
}
