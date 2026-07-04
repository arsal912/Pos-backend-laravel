<?php

namespace App\Observers;

use App\Jobs\SyncStoreAggregate;
use App\Models\Sale;
use App\Models\Store;

class SaleObserver
{
    public function created(Sale $sale): void
    {
        if ($sale->status === 'completed') {
            $this->syncAggregate();
        }
    }

    public function updated(Sale $sale): void
    {
        if ($sale->wasChanged('status')) {
            $this->syncAggregate();
        }
    }

    private function syncAggregate(): void
    {
        try {
            $store = app('current_store');
            if ($store instanceof Store) {
                SyncStoreAggregate::dispatch($store);
            }
        } catch (\Throwable) {
            // Non-fatal — aggregate sync is best-effort
        }
    }
}
