<?php

use App\Jobs\SyncStoreAggregate;
use App\Models\ApiLog;
use App\Models\Plan;
use App\Models\Store;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager as TenancyDatabaseManager;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Purge old API logs (default retention: 30 days)
 * Usage: php artisan api-logs:purge [--days=30]
 */
Artisan::command('api-logs:purge {--days=30}', function () {
    $days = (int) $this->option('days');
    $deleted = ApiLog::where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Deleted {$deleted} API log entries older than {$days} days.");
})->purpose('Purge old API log entries');

// Schedule API log cleanup daily at 2am
Schedule::command('api-logs:purge --days=' . config('api-logging.retention_days', 30))
    ->dailyAt('02:00');

// Schedule store aggregate synchronization once per day
Schedule::command('store-aggregates:sync')
    ->dailyAt('03:00');

Artisan::command('store-aggregates:sync', function () {
    $this->info('Syncing store aggregate metrics...');

    Store::chunk(50, function ($stores) {
        foreach ($stores as $store) {
            SyncStoreAggregate::dispatch($store);
        }
    });

    $this->info('Store aggregate sync dispatch complete.');
})->purpose('Dispatch SyncStoreAggregate for every active store');

Artisan::command('tenancy:migrate-existing-stores {--force}', function () {
    $force = $this->option('force');
    $this->info('Migrating all existing tenant stores...');

    Store::chunk(25, function ($stores) use ($force) {
        foreach ($stores as $store) {
            $databaseName = $store->database()->getName();
            $this->info("Store {$store->id} ({$store->name}) -> {$databaseName}");

            $manager = $store->database()->manager();
            if (! $manager->databaseExists($databaseName)) {
                $this->comment('Tenant database missing, creating...');
                app(TenancyDatabaseManager::class)->ensureTenantCanBeCreated($store);
                $manager->createDatabase($store);
            }

            Artisan::call('tenants:migrate', [
                '--tenants' => [$store->getTenantKey()],
                '--force' => true,
            ]);

            $this->info('Migration complete.');
        }
    });

    $this->info('Completed migration for all existing stores.');
})->purpose('Migrate all existing tenant store databases');

Artisan::command('tenant:export {storeId} {path?}', function ($storeId, $path = null) {
    $store = Store::findOrFail($storeId);
    $path ??= storage_path('app/tenant-export-store-' . $store->id . '-' . now()->format('YmdHis') . '.json');

    if (! preg_match('/^[A-Za-z]:(\\\\|\\/)|^[\\/\\/]/', $path) && ! Str::startsWith($path, [DIRECTORY_SEPARATOR, '/'])) {
        $path = storage_path('app/' . ltrim($path, '/\\'));
    }

    File::ensureDirectoryExists(dirname($path));

    $exported = [];
    $store->run(function () use (&$exported) {
        $connection = DB::connection();
        $exported['tenant_database'] = $connection->getDatabaseName();
        $exported['exported_at'] = now()->toDateTimeString();
        $exported['tables'] = [];

        $tables = collect(DB::select('SHOW TABLES'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->filter(fn ($table) => $table !== 'migrations');

        foreach ($tables as $table) {
            $exported['tables'][] = [
                'name' => $table,
                'rows' => DB::table($table)->get()->map(fn ($record) => (array) $record)->all(),
            ];
        }
    });

    File::put($path, json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->info("Tenant export completed: {$path}");
})->purpose('Export a tenant database to a JSON file');

Artisan::command('tenant:import {storeId} {path}', function ($storeId, $path) {
    $store = Store::findOrFail($storeId);

    if (! preg_match('/^[A-Za-z]:(\\\\|\\/)|^[\\/\\/]/', $path) && ! Str::startsWith($path, [DIRECTORY_SEPARATOR, '/'])) {
        $path = storage_path('app/' . ltrim($path, '/\\'));
    }

    if (! File::exists($path)) {
        $this->error("Import file not found: {$path}");
        return;
    }

    $payload = json_decode(File::get($path), true);
    if (! isset($payload['tables']) || ! is_array($payload['tables'])) {
        $this->error('Invalid tenant export format.');
        return;
    }

    $this->info('Running tenant migrations before import...');
    Artisan::call('tenants:migrate', [
        '--tenants' => [$store->getTenantKey()],
        '--force' => true,
    ]);

    $store->run(function () use ($payload) {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');

        foreach ($payload['tables'] as $tableData) {
            $table = $tableData['name'];
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();

            foreach (array_chunk($tableData['rows'], 100) as $batch) {
                DB::table($table)->insert($batch);
            }
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
    });

    $this->info('Tenant import completed successfully.');
})->purpose('Import tenant database rows from a JSON file');

Artisan::command('tenancy:verify-existing-stores {--fix}', function () {
    $fix = $this->option('fix');
    $this->info('Verifying existing tenant stores...');

    Store::chunk(25, function ($stores) use ($fix) {
        foreach ($stores as $store) {
            $databaseName = $store->database()->getName();
            $manager = $store->database()->manager();
            $exists = $manager->databaseExists($databaseName);

            $this->info("Store {$store->id} ({$store->name}) -> {$databaseName}: " . ($exists ? 'OK' : 'MISSING'));

            if (! $exists) {
                if ($fix) {
                    app(TenancyDatabaseManager::class)->ensureTenantCanBeCreated($store);
                    $manager->createDatabase($store);
                    $this->comment('Created missing tenant database.');
                }
            }

            if (! $store->aggregate) {
                $this->warn('Missing store aggregate row.');
                if ($fix) {
                    SyncStoreAggregate::dispatch($store);
                    $this->comment('Dispatched aggregate sync job.');
                }
            }
        }
    });

    $this->info('Tenant verification complete.');
})->purpose('Verify tenant database setup and central aggregate records');

/*
 * Sync local Plans to Stripe Products + Prices.
 * Stores stripe_product_id and stripe_price_id back on the plans table.
 * Usage: php artisan stripe:sync-plans
 */
Artisan::command('stripe:sync-plans', function () {
    $gateway = PaymentGateway::where('slug', 'stripe')->first();

    if (! $gateway || ! $gateway->is_active) {
        $this->warn('Stripe gateway is not active. Enable it in Admin → Payment Gateways first.');
        return;
    }

    $credentials = $gateway->credentials;
    $secretKey = $credentials['secret_key'] ?? null;

    if (! $secretKey) {
        $this->error('Stripe secret key not configured. Add it in Admin → Payment Gateways → Configure.');
        return;
    }

    $stripe = new \Stripe\StripeClient($secretKey);
    $plans = Plan::where('is_active', true)->where('price', '>', 0)->get();

    if ($plans->isEmpty()) {
        $this->info('No paid plans found to sync.');
        return;
    }

    foreach ($plans as $plan) {
        $this->line("Syncing plan: {$plan->name} (\${$plan->price}/{$plan->billing_cycle})");

        try {
            // Create or update Stripe Product
            if ($plan->stripe_product_id) {
                $product = $stripe->products->update($plan->stripe_product_id, [
                    'name' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                ]);
            } else {
                $product = $stripe->products->create([
                    'name' => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'metadata' => ['plan_id' => $plan->id, 'plan_slug' => $plan->slug],
                ]);
            }

            // Create a new Price (Stripe prices are immutable once created)
            $interval = match ($plan->billing_cycle) {
                'yearly' => 'year',
                default  => 'month',
            };

            $price = $stripe->prices->create([
                'product' => $product->id,
                'unit_amount' => (int) round($plan->price * 100),
                'currency' => strtolower($plan->currency ?? 'usd'),
                'recurring' => ['interval' => $interval],
                'metadata' => ['plan_id' => $plan->id],
            ]);

            $plan->update([
                'stripe_product_id' => $product->id,
                'stripe_price_id' => $price->id,
            ]);

            $this->info("  ✓ Product: {$product->id} | Price: {$price->id}");
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->error("  ✗ Failed: {$e->getMessage()}");
        }
    }

    $this->info('Stripe plan sync complete.');
})->purpose('Sync local plans to Stripe Products and Prices');

Artisan::command('phase2.5:verify {--fix}', function () {
    $fix = $this->option('fix');

    $this->info('Running Phase 2.5 verification...');

    $this->call('tenancy:verify-existing-stores', ['--fix' => $fix]);
    $this->call('tenancy:migrate-existing-stores', ['--force' => true]);
    $this->call('store-aggregates:sync');

    $this->info('Phase 2.5 verification complete.');
})->purpose('Run a full Phase 2.5 tenancy verification pass');
