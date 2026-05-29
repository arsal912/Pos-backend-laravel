<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('status');
            }
            if (!Schema::hasColumn('payments', 'last_retry_at')) {
                $table->timestamp('last_retry_at')->nullable()->after('retry_count');
            }
            if (!Schema::hasColumn('payments', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('last_retry_at');
            }
            if (!Schema::hasColumn('payments', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('failure_reason');
            }
            if (!Schema::hasColumn('payments', 'refund_amount')) {
                $table->decimal('refund_amount', 10, 2)->nullable()->after('refunded_at');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            try {
                $table->unique(['gateway', 'gateway_payment_id'], 'payments_gateway_gateway_payment_id_unique');
            } catch (\Throwable $e) {
                // Index may already exist.
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
            if (Schema::hasColumn('payments', 'last_retry_at')) {
                $table->dropColumn('last_retry_at');
            }
            if (Schema::hasColumn('payments', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
            if (Schema::hasColumn('payments', 'refunded_at')) {
                $table->dropColumn('refunded_at');
            }
            if (Schema::hasColumn('payments', 'refund_amount')) {
                $table->dropColumn('refund_amount');
            }

            try {
                $table->dropUnique('payments_gateway_gateway_payment_id_unique');
            } catch (\Throwable $e) {
                // Index may not exist
            }
        });
    }
};
