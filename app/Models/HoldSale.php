<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HoldSale extends Model
{
    protected $fillable = [
        'branch_id', 'cashier_id', 'customer_id', 'name', 'data',
    ];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
