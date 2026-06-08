<?php

use App\Jobs\SyncStoreAggregate;
use App\Mail\ManualRenewalReminder;
use App\Mail\SubscriptionWarning;
use App\Mail\SubscriptionExpired;
use App\Mail\TrialEnded;
use App\Models\ApiLog;
use App\Models\Customer;
use App\Models\LoyaltySettings;
use App\Models\Plan;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\PaymentGateway;
use App\Models\ScheduledReport;
use App\Services\LoyaltyService;
use App\Services\CreditService;
use App\Services\Reports\ReportManager;
use App\Services\Reports\ReportExporter;
use Illuminate\Support\Facades\Mail;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use App\Models\CommunicationQuota;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\DatabaseManager as TenancyDatabaseManager;

/*
 * Seed demo data into a tenant DB for report testing.
 * Usage: php artisan tenant:seed-demo {storeId}
 * Creates ~500 sales, 30 customers, 12 products over 90 days.
 */
Artisan::command('tenant:seed-demo {storeId}', function ($storeId) {
    $seeder = new \Database\Seeders\DemoDataSeeder();
    $seeder->setCommand($this);
    $seeder->run((int) $storeId);
})->purpose('Seed demo data into a tenant DB for Phase 4D report testing');

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
// =============================================================================
// SUBSCRIPTION LIFECYCLE COMMANDS
// =============================================================================

/*
 * Send expiry warning emails (7d, 3d, 1d before expiry).
 * Uses warning_sent_* flags to prevent duplicate sends.
 * Scheduled daily at 1am.
 */
Artisan::command('subscriptions:check-expiring', function () {
    $sent = 0;

    foreach ([7, 3, 1] as $days) {
        $field       = "warning_sent_{$days}d";
        $windowStart = now()->addDays($days)->startOfDay();
        $windowEnd   = now()->addDays($days)->endOfDay();

        $subs = Subscription::with(['store', 'plan'])
            ->where('status', 'active')
            ->whereBetween('ends_at', [$windowStart, $windowEnd])
            ->whereNull($field)
            ->get();

        foreach ($subs as $sub) {
            if (! $sub->store?->email || ! $sub->plan) {
                continue;
            }

            try {
                Mail::to($sub->store->email)
                    ->send(new SubscriptionWarning($sub, $days));

                $sub->update([$field => now()]);
                $sent++;
                $this->line("  Sent {$days}d warning → {$sub->store->email}");
            } catch (\Throwable $e) {
                $this->error("  Failed for store {$sub->store_id}: {$e->getMessage()}");
            }
        }
    }

    $this->info("Expiry warnings sent: {$sent}");
})->purpose('Send subscription expiry warning emails at 7, 3, and 1 day before expiry');

Schedule::command('subscriptions:check-expiring')->dailyAt('01:00');

/*
 * Expire subscriptions whose ends_at has passed (and grace period too).
 * Sets subscription status=expired and store status=expired.
 * Sends expiration email with reactivation link.
 * Scheduled daily at 2am.
 */
Artisan::command('subscriptions:expire', function () {
    $expired = 0;

    $subs = Subscription::with(['store', 'plan'])
        ->where('status', 'active')
        ->where('ends_at', '<', now())
        ->where(function ($q) {
            $q->whereNull('grace_period_ends_at')
              ->orWhere('grace_period_ends_at', '<', now());
        })
        ->get();

    foreach ($subs as $sub) {
        $sub->update(['status' => 'expired']);

        // Mark the store as expired so TenantScope returns 402
        if ($sub->store && $sub->store->status !== 'expired') {
            $sub->store->update(['status' => 'expired']);
        }

        \App\Models\PaymentEvent::create([
            'store_id'        => $sub->store_id,
            'subscription_id' => $sub->id,
            'event_type'      => 'subscription_expired',
            'gateway'         => $sub->payment_gateway ?? 'system',
            'data'            => ['expired_at' => now()->toDateTimeString()],
        ]);

        if ($sub->store?->email) {
            try {
                Mail::to($sub->store->email)
                    ->send(new SubscriptionExpired($sub));
                $this->line("  Expired + emailed → {$sub->store->email}");
            } catch (\Throwable $e) {
                $this->error("  Email failed for store {$sub->store_id}: {$e->getMessage()}");
            }
        }

        $expired++;
    }

    $this->info("Subscriptions expired: {$expired}");
})->purpose('Expire subscriptions past their end date and send expiration emails');

Schedule::command('subscriptions:expire')->dailyAt('02:00');

/*
 * Expire stores whose free trial has ended with no active paid subscription.
 * Sends trial-ended upgrade email.
 * Scheduled daily at 2:30am.
 */
Artisan::command('trials:expire', function () {
    $expired = 0;

    $stores = Store::where('status', 'active')
        ->whereNotNull('trial_ends_at')
        ->where('trial_ends_at', '<', now())
        ->whereDoesntHave('subscriptions', fn ($q) => $q->where('status', 'active'))
        ->get();

    foreach ($stores as $store) {
        $store->update(['status' => 'expired']);

        if ($store->email) {
            try {
                Mail::to($store->email)->send(new TrialEnded($store));
                $this->line("  Trial expired + emailed → {$store->email}");
            } catch (\Throwable $e) {
                $this->error("  Email failed for store {$store->id}: {$e->getMessage()}");
            }
        }

        $expired++;
    }

    $this->info("Trials expired: {$expired}");
})->purpose('Expire stores whose free trial has ended with no active subscription');

Schedule::command('trials:expire')->dailyAt('02:30');

/*
 * Retry failed Stripe invoice payments for subscriptions in grace period.
 * For JazzCash/Easypaisa (no API retry): the manual reminder handles those.
 * Scheduled daily at 3am.
 */
Artisan::command('subscriptions:retry-failed', function () {
    $retried = 0;

    $stripeSubs = Subscription::with(['store', 'plan'])
        ->where('status', 'pending')
        ->where('payment_gateway', 'stripe')
        ->whereNotNull('gateway_subscription_id')
        ->where('grace_period_ends_at', '>', now())
        ->get()
        // Skip subscriptions that already have 3+ failed payment attempts
        ->filter(function ($sub) {
            return \App\Models\Payment::where('subscription_id', $sub->id)
                ->where('status', 'failed')
                ->count() < 3;
        });

    if ($stripeSubs->isEmpty()) {
        $this->info('No Stripe subscriptions to retry.');
        return;
    }

    $gateway = PaymentGateway::where('slug', 'stripe')->first();
    if (! $gateway?->is_active) {
        $this->warn('Stripe gateway is not active — skipping retry.');
        return;
    }

    foreach ($stripeSubs as $sub) {
        $this->line("  Retrying Stripe sub {$sub->id} for store {$sub->store_id}...");

        try {
            // Build a raw Stripe client — invoices->pay() isn't on StripeService
            $credentials = $gateway->credentials;
            $client      = new \Stripe\StripeClient($credentials['secret_key'] ?? '');
            $invoices    = $client->invoices->all([
                'subscription' => $sub->gateway_subscription_id,
                'status'       => 'open',
                'limit'        => 1,
            ]);

            if (empty($invoices->data)) {
                $this->line("    No open invoices found.");
                continue;
            }

            $invoice = $invoices->data[0];
            $client->invoices->pay($invoice->id);

            // Success — clear grace period
            $sub->update([
                'status'               => 'active',
                'grace_period_ends_at' => null,
            ]);

            $this->info("    Retry succeeded.");
            $retried++;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe retry failed — increment counter, send another warning
            $newCount = ($sub->retry_count ?? 0) + 1;
            $sub->update([
                'retry_count'   => $newCount,
                'last_retry_at' => now(),
            ]);

            $this->error("    Retry failed ({$newCount}/3): {$e->getMessage()}");

            if ($sub->store?->email) {
                try {
                    Mail::to($sub->store->email)
                        ->send(new \App\Mail\PaymentFailed($sub, $e->getMessage()));
                } catch (\Throwable) {
                    // Non-fatal
                }
            }
        } catch (\Throwable $e) {
            $this->error("    Unexpected error: {$e->getMessage()}");
        }
    }

    $this->info("Stripe retries completed: {$retried}");
})->purpose('Retry failed Stripe invoice payments for subscriptions in grace period');

Schedule::command('subscriptions:retry-failed')->dailyAt('03:00');

// =============================================================================

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

/*
 * Sync local Plans to PayPal Products + Billing Plans.
 * Stores paypal_plan_id back on the plans table.
 * Usage: php artisan paypal:sync-plans
 */
/*
 * Send renewal reminders for JazzCash and Easypaisa subscriptions
 * (gateways that don't support auto-billing, so users must pay manually).
 * Scheduled daily at 9am. Finds subs expiring in 1, 3, or 7 days.
 * Usage: php artisan renewals:send-manual-reminders
 */
// =============================================================================
// PHASE 5 — COMMUNICATIONS QUOTA RESET
// =============================================================================

/*
 * Reset daily communication quota counters for all tenant DBs.
 * Runs hourly — checks each channel's quota_resets_at timestamp.
 * Usage: php artisan communications:reset-daily-quotas
 */
Artisan::command('communications:reset-daily-quotas', function () {
    $reset = 0;
    Store::chunk(20, function ($stores) use (&$reset) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use (&$reset) {
                    if (! \Illuminate\Support\Facades\Schema::hasTable('communication_quotas')) return;
                    $quota = CommunicationQuota::current();
                    $now   = now();
                    $changed = false;

                    foreach (['sms', 'email', 'whatsapp'] as $channel) {
                        $resetsAt = $quota->{"{$channel}_quota_resets_at"};
                        if ($resetsAt && $resetsAt->lt($now)) {
                            $quota->{"{$channel}_sent_today"}       = 0;
                            $quota->{"{$channel}_quota_resets_at"}  = $now->copy()->addDay()->startOfDay();
                            $changed = true;
                        }
                    }

                    if ($changed) { $quota->save(); $reset++; }
                });
            } catch (\Throwable) {}
        }
    });
    $this->info("Quota counters reset for {$reset} tenant(s).");
})->purpose('Reset daily communication quota counters for all tenants');

Schedule::command('communications:reset-daily-quotas')->hourly();

// =============================================================================
// PHASE 4D — SCHEDULED REPORTS DISPATCH
// =============================================================================

/*
 * Check all tenant DBs for scheduled reports that are due and log them.
 * In Phase 5, this will send real emails with attachments.
 * Runs every 15 minutes.
 * Usage: php artisan reports:dispatch-scheduled
 */
Artisan::command('reports:dispatch-scheduled', function () {
    $dispatched = 0;
    $errors     = 0;

    Store::where('status', 'active')->chunk(10, function ($stores) use (&$dispatched, &$errors) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use ($store, &$dispatched, &$errors) {
                    if (! \Illuminate\Support\Facades\Schema::hasTable('scheduled_reports')) return;

                    $due = ScheduledReport::where('is_active', true)->get()->filter->isDue();

                    foreach ($due as $schedule) {
                        try {
                            $manager  = app(ReportManager::class);
                            $exporter = app(ReportExporter::class);

                            if (! $manager->has($schedule->report_slug)) continue;

                            $report  = $manager->get($schedule->report_slug);
                            $filters = array_merge($report->getDefaultFilters(), $schedule->filters ?? []);
                            $result  = $report->run($filters);

                            // Log to communication_logs for each recipient
                            $body = "Scheduled Report: {$schedule->name}\n"
                                  . "Generated: " . now()->toDateTimeString() . "\n"
                                  . "Rows: " . $result->meta['row_count'] . "\n\n"
                                  . implode("\n", array_map(
                                        fn($c) => $c['label'] . ': ' . $c['value'],
                                        $result->summary
                                    ));

                            foreach ($schedule->recipient_emails as $email) {
                                \App\Models\CommunicationLog::create([
                                    'customer_id'    => null,
                                    'recipient'      => $email,
                                    'channel'        => 'email',
                                    'type'           => 'transactional',
                                    'subject'        => "Scheduled Report: {$schedule->name}",
                                    'body'           => $body,
                                    'status'         => 'skipped',
                                    'provider'       => 'logged_only',
                                    'sent_at'        => now(),
                                    'sent_by'        => 0,
                                    'reference_type' => 'scheduled_report',
                                    'reference_id'   => $schedule->id,
                                ]);
                            }

                            $schedule->update([
                                'last_sent_at' => now(),
                                'last_status'  => 'success',
                                'last_error'   => null,
                            ]);

                            $dispatched++;
                        } catch (\Throwable $e) {
                            $schedule->update(['last_status'=>'error','last_error'=>$e->getMessage()]);
                            $errors++;
                        }
                    }
                });
            } catch (\Throwable $e) {
                // Store may not have the table yet — skip
            }
        }
    });

    $this->info("Reports dispatched: {$dispatched}, errors: {$errors}");
})->purpose('Dispatch due scheduled reports across all tenant DBs (Phase 5 will send real emails)');

Schedule::command('reports:dispatch-scheduled')->everyFifteenMinutes();

// =============================================================================
// PHASE 4C — LOYALTY & CREDIT SCHEDULED COMMANDS
// =============================================================================

/*
 * Expire loyalty points past their expires_at date.
 * Runs across all tenant DBs that have loyalty tables.
 * Scheduled daily at 1am.
 */
Artisan::command('loyalty:expire-points', function () {
    $expired = 0;
    Store::chunk(20, function ($stores) use (&$expired) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use (&$expired) {
                    if (! Schema::hasTable('loyalty_transactions')) return;
                    $settings = LoyaltySettings::current();
                    if (! $settings->is_enabled || ! $settings->points_expiry_days) return;
                    $service = app(LoyaltyService::class);
                    $count   = $service->expirePoints();
                    $expired += $count;
                });
            } catch (\Throwable $e) {
                $this->error("Store {$store->id}: {$e->getMessage()}");
            }
        }
    });
    $this->info("Total points expired across all tenants: {$expired}");
})->purpose('Expire loyalty points past their expiry date across all tenant DBs');

Schedule::command('loyalty:expire-points')->dailyAt('01:00');

/*
 * Award birthday bonus points to customers whose birthday is today.
 * Scheduled daily at 8am.
 */
Artisan::command('loyalty:birthday-bonuses', function () {
    $awarded = 0;
    Store::chunk(20, function ($stores) use (&$awarded) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use (&$awarded) {
                    if (! Schema::hasTable('customers') || ! Schema::hasTable('loyalty_settings')) return;
                    $settings = LoyaltySettings::current();
                    if (! $settings->is_enabled || $settings->birthday_bonus_points <= 0) return;

                    $today   = now()->format('m-d'); // MM-DD
                    $service = app(LoyaltyService::class);

                    Customer::whereRaw("DATE_FORMAT(date_of_birth, '%m-%d') = ?", [$today])
                        ->where('is_active', true)
                        ->each(function (Customer $c) use ($service, &$awarded) {
                            $tx = $service->applyBirthdayBonus($c->id);
                            if ($tx) $awarded++;
                        });
                });
            } catch (\Throwable $e) {
                $this->error("Store {$store->id}: {$e->getMessage()}");
            }
        }
    });
    $this->info("Birthday bonuses awarded: {$awarded}");
})->purpose('Award birthday bonus points to customers whose birthday is today');

Schedule::command('loyalty:birthday-bonuses')->dailyAt('08:00');

/*
 * Update customer lifetime stats (lifetime_value, total_purchases_count, last_purchase_at).
 * Reads from the sales table in tenant DB and denormalizes onto customers row.
 * Scheduled daily at 4am.
 */
Artisan::command('customers:update-lifetime-stats', function () {
    $updated = 0;
    Store::chunk(20, function ($stores) use (&$updated) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use (&$updated) {
                    if (! Schema::hasTable('sales') || ! Schema::hasTable('customers')) return;

                    DB::table('customers')
                        ->where('is_active', true)
                        ->whereNull('deleted_at')
                        ->orderBy('id')
                        ->each(function ($customer) use (&$updated) {
                            $stats = DB::table('sales')
                                ->where('customer_id', $customer->id)
                                ->where('status', 'completed')
                                ->selectRaw('COUNT(*) as count, SUM(total) as total, MAX(sale_date) as last')
                                ->first();

                            if (! $stats) return;

                            DB::table('customers')->where('id', $customer->id)->update([
                                'lifetime_value'       => (float) ($stats->total ?? 0),
                                'total_purchases_count'=> (int)   ($stats->count ?? 0),
                                'last_purchase_at'     => $stats->last,
                            ]);
                            $updated++;
                        });
                });
            } catch (\Throwable $e) {
                $this->error("Store {$store->id}: {$e->getMessage()}");
            }
        }
    });
    $this->info("Customer lifetime stats updated: {$updated} customers");
})->purpose('Denormalize customer lifetime_value, total_purchases_count, last_purchase_at from sales');

Schedule::command('customers:update-lifetime-stats')->dailyAt('04:00');

/*
 * Recompute customer credit balances from credit_transactions (integrity check).
 * Reports discrepancies. Scheduled weekly Sunday at 4am.
 */
Artisan::command('credit:recompute-balances', function () {
    $checked = 0; $fixed = 0;
    Store::chunk(20, function ($stores) use (&$checked, &$fixed) {
        foreach ($stores as $store) {
            try {
                $store->run(function () use (&$checked, &$fixed) {
                    if (! Schema::hasTable('credit_transactions') || ! Schema::hasTable('customers')) return;
                    $service = app(CreditService::class);

                    Customer::where('outstanding_balance', '>', 0)->each(function (Customer $c) use ($service, &$checked, &$fixed) {
                        $checked++;
                        $computed = DB::table('credit_transactions')
                            ->where('customer_id', $c->id)
                            ->sum('amount');
                        $computed = max(0, (float) $computed);

                        if (abs($computed - (float) $c->outstanding_balance) > 0.01) {
                            $service->recompute($c->id);
                            $fixed++;
                        }
                    });
                });
            } catch (\Throwable $e) {
                $this->error("Store {$store->id}: {$e->getMessage()}");
            }
        }
    });
    $this->info("Credit balances checked: {$checked}, discrepancies fixed: {$fixed}");
})->purpose('Recompute customer credit balances from credit_transactions for integrity check');

Schedule::command('credit:recompute-balances')->weeklyOn(0, '04:00'); // Sunday 4am

// =============================================================================

Artisan::command('renewals:send-manual-reminders', function () {
    $gateways    = ['jazzcash', 'easypaisa'];
    $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');

    $sent = 0;

    foreach ([7, 3, 1] as $daysAhead) {
        $windowStart = now()->addDays($daysAhead)->startOfDay();
        $windowEnd   = now()->addDays($daysAhead)->endOfDay();

        $subscriptions = Subscription::with(['store', 'plan'])
            ->whereIn('payment_gateway', $gateways)
            ->where('status', 'active')
            ->whereBetween('next_billing_at', [$windowStart, $windowEnd])
            ->get();

        foreach ($subscriptions as $sub) {
            if (! $sub->store?->email || ! $sub->plan) {
                continue;
            }

            $renewUrl = $frontendUrl . '/billing/renew?subscription_id=' . $sub->id;

            try {
                Mail::to($sub->store->email)
                    ->send(new ManualRenewalReminder($sub, $renewUrl, $sub->payment_gateway));
                $sent++;
                $this->line("  Sent {$daysAhead}d reminder → {$sub->store->email} ({$sub->payment_gateway})");
            } catch (\Throwable $e) {
                $this->error("  Failed for store {$sub->store_id}: {$e->getMessage()}");
            }
        }
    }

    $this->info("Renewal reminders sent: {$sent}");
})->purpose('Send manual renewal reminders for JazzCash and Easypaisa subscriptions');

// Schedule daily at 9am
Schedule::command('renewals:send-manual-reminders')->dailyAt('09:00');

Artisan::command('paypal:sync-plans', function () {
    $gateway = PaymentGateway::where('slug', 'paypal')->first();

    if (! $gateway || ! $gateway->is_active) {
        $this->warn('PayPal gateway is not active. Enable it in Admin → Payment Gateways first.');
        return;
    }

    $credentials  = $gateway->credentials;
    $clientId     = $credentials['client_id'] ?? null;
    $clientSecret = $credentials['client_secret'] ?? null;

    if (! $clientId || ! $clientSecret) {
        $this->error('PayPal client_id and client_secret not configured.');
        return;
    }

    $mode    = $credentials['mode'] ?? env('PAYPAL_MODE', 'sandbox');
    $baseUrl = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    $http    = new \GuzzleHttp\Client(['timeout' => 30]);

    // Get OAuth token
    $tokenRes = $http->post("{$baseUrl}/v1/oauth2/token", [
        'auth'        => [$clientId, $clientSecret],
        'form_params' => ['grant_type' => 'client_credentials'],
    ]);
    $token = json_decode((string) $tokenRes->getBody(), true)['access_token'] ?? null;

    if (! $token) {
        $this->error('Failed to get PayPal access token. Check credentials.');
        return;
    }

    $headers = ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'];
    $plans   = Plan::where('is_active', true)->where('price', '>', 0)->get();

    if ($plans->isEmpty()) {
        $this->info('No paid plans found to sync.');
        return;
    }

    foreach ($plans as $plan) {
        $this->line("Syncing plan: {$plan->name} (\${$plan->price}/{$plan->billing_cycle})");

        try {
            // Create PayPal Product
            $productRes = $http->post("{$baseUrl}/v1/catalogs/products", [
                'headers' => $headers,
                'json'    => [
                    'name'        => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'type'        => 'SERVICE',
                    'category'    => 'SOFTWARE',
                ],
            ]);
            $product = json_decode((string) $productRes->getBody(), true);

            // Create PayPal Billing Plan
            $interval = match ($plan->billing_cycle) {
                'yearly' => ['interval_unit' => 'YEAR', 'interval_count' => 1],
                default  => ['interval_unit' => 'MONTH', 'interval_count' => 1],
            };

            $billingPlanRes = $http->post("{$baseUrl}/v1/billing/plans", [
                'headers' => $headers,
                'json'    => [
                    'product_id'  => $product['id'],
                    'name'        => $plan->name,
                    'description' => $plan->description ?? $plan->name,
                    'status'      => 'ACTIVE',
                    'billing_cycles' => [
                        [
                            'frequency'       => $interval,
                            'tenure_type'     => 'REGULAR',
                            'sequence'        => 1,
                            'total_cycles'    => 0,
                            'pricing_scheme'  => [
                                'fixed_price' => [
                                    'value'         => (string) $plan->price,
                                    'currency_code' => strtoupper($plan->currency ?? 'USD'),
                                ],
                            ],
                        ],
                    ],
                    'payment_preferences' => [
                        'auto_bill_outstanding'     => true,
                        'setup_fee_failure_action'  => 'CONTINUE',
                        'payment_failure_threshold' => 3,
                    ],
                ],
            ]);
            $billingPlan = json_decode((string) $billingPlanRes->getBody(), true);

            $plan->update(['paypal_plan_id' => $billingPlan['id']]);

            $this->info("  ✓ Product: {$product['id']} | Plan: {$billingPlan['id']}");
        } catch (\Throwable $e) {
            $this->error("  ✗ Failed: {$e->getMessage()}");
        }
    }

    $this->info('PayPal plan sync complete.');
})->purpose('Sync local plans to PayPal Products and Billing Plans');

Artisan::command('phase2.5:verify {--fix}', function () {
    $fix = $this->option('fix');

    $this->info('Running Phase 2.5 verification...');

    $this->call('tenancy:verify-existing-stores', ['--fix' => $fix]);
    $this->call('tenancy:migrate-existing-stores', ['--force' => true]);
    $this->call('store-aggregates:sync');

    $this->info('Phase 2.5 verification complete.');
})->purpose('Run a full Phase 2.5 tenancy verification pass');
