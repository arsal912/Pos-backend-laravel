<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashDrawerSession extends Model
{
    protected $fillable = [
        'branch_id', 'cashier_id',
        'opened_at', 'opening_balance',
        'closed_at', 'closing_balance',
        'expected_balance', 'over_short', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at'        => 'datetime',
            'closed_at'        => 'datetime',
            'opening_balance'  => 'decimal:2',
            'closing_balance'  => 'decimal:2',
            'expected_balance' => 'decimal:2',
            'over_short'       => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
