<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds missing performance indexes identified during Phase 3 analysis.
     *
     * Indexes that already existed at the time of writing (no-ops here, kept
     * for documentation so future readers understand the full intended index
     * set on each table):
     *
     *   sales:
     *     - sales_cashier_id_sale_date_index         (cashier_id, sale_date)
     *     - sales_branch_id_sale_date_status_index   (branch_id, sale_date, status)
     *     - sales_customer_id_sale_date_index        (customer_id, sale_date)
     *
     *   stock_movements:
     *     - stock_movements_product_id_variant_id_created_at_index (product_id, variant_id, created_at)
     *     - stock_movements_branch_id_created_at_index             (branch_id, created_at)
     *
     *   communication_logs:
     *     - communication_logs_customer_id_created_at_index (customer_id, created_at)
     *
     *   loyalty_transactions:
     *     - loyalty_transactions_customer_id_created_at_index (customer_id, created_at)
     */
    public function up(): void
    {
        // ─── sales ────────────────────────────────────────────────────────────────

        // Supports filtering / grouping by customer + status (e.g. outstanding
        // credit reports, per-customer completed-sale counts).
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('sales');

                if (! array_key_exists('idx_sales_customer_status', $sm)) {
                    $table->index(['customer_id', 'status'], 'idx_sales_customer_status');
                }

                // Standalone date index for fast date-range report scans where
                // branch / cashier are not part of the filter.
                if (! array_key_exists('idx_sales_date', $sm)) {
                    $table->index('sale_date', 'idx_sales_date');
                }
            });
        }

        // ─── stock_movements ──────────────────────────────────────────────────────

        // Supports movement-type breakdowns per branch (e.g. "all sales
        // movements for branch X this month").
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('stock_movements');

                if (! array_key_exists('idx_movements_branch_type', $sm)) {
                    $table->index(['branch_id', 'type'], 'idx_movements_branch_type');
                }
            });
        }

        // ─── communication_logs ───────────────────────────────────────────────────

        // Supports customer communication history queries filtered by channel
        // (e.g. "all SMS messages sent to customer X, ordered by date").
        if (Schema::hasTable('communication_logs')) {
            Schema::table('communication_logs', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('communication_logs');

                if (! array_key_exists('idx_commlogs_customer_channel_date', $sm)) {
                    $table->index(['customer_id', 'channel', 'created_at'], 'idx_commlogs_customer_channel_date');
                }
            });
        }

        // ─── loyalty_transactions ─────────────────────────────────────────────────
        // Already covered by loyalty_transactions_customer_id_created_at_index
        // created in 2026_06_01_000016_create_loyalty_tables.php.
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('sales');

                if (array_key_exists('idx_sales_customer_status', $sm)) {
                    $table->dropIndex('idx_sales_customer_status');
                }
                if (array_key_exists('idx_sales_date', $sm)) {
                    $table->dropIndex('idx_sales_date');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('stock_movements');

                if (array_key_exists('idx_movements_branch_type', $sm)) {
                    $table->dropIndex('idx_movements_branch_type');
                }
            });
        }

        if (Schema::hasTable('communication_logs')) {
            Schema::table('communication_logs', function (Blueprint $table) {
                $sm = $table->getConnection()
                    ->getDoctrineSchemaManager()
                    ->listTableIndexes('communication_logs');

                if (array_key_exists('idx_commlogs_customer_channel_date', $sm)) {
                    $table->dropIndex('idx_commlogs_customer_channel_date');
                }
            });
        }
    }
};
