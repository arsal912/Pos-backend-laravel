<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'transfer_number', 'from_branch_id', 'to_branch_id',
        'transfer_date', 'received_date', 'status',
        'notes', 'created_by', 'received_by',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'received_date' => 'date',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }
}
