<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'grace_period_ends_at')) {
                $table->timestamp('grace_period_ends_at')->nullable()->after('ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'next_billing_at')) {
                $table->timestamp('next_billing_at')->nullable()->after('grace_period_ends_at');
            }
            if (!Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->boolean('auto_renew')->default(true)->after('next_billing_at');
            }
            if (!Schema::hasColumn('subscriptions', 'gateway_customer_id')) {
                $table->string('gateway_customer_id')->nullable()->after('auto_renew');
            }
            if (!Schema::hasColumn('subscriptions', 'gateway_subscription_id')) {
                $table->string('gateway_subscription_id')->nullable()->after('gateway_customer_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('subscriptions', 'gateway_subscription_id')) {
                $table->dropColumn('gateway_subscription_id');
            }
            if (Schema::hasColumn('subscriptions', 'gateway_customer_id')) {
                $table->dropColumn('gateway_customer_id');
            }
            if (Schema::hasColumn('subscriptions', 'auto_renew')) {
                $table->dropColumn('auto_renew');
            }
            if (Schema::hasColumn('subscriptions', 'next_billing_at')) {
                $table->dropColumn('next_billing_at');
            }
            if (Schema::hasColumn('subscriptions', 'grace_period_ends_at')) {
                $table->dropColumn('grace_period_ends_at');
            }
        });
    }
};
