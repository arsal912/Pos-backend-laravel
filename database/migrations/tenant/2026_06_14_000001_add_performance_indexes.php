<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if (! $this->indexExists('sales', 'idx_sales_customer_status')) {
                    $table->index(['customer_id', 'status'], 'idx_sales_customer_status');
                }
                if (! $this->indexExists('sales', 'idx_sales_date')) {
                    $table->index('sale_date', 'idx_sales_date');
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! $this->indexExists('stock_movements', 'idx_movements_branch_type')) {
                    $table->index(['branch_id', 'type'], 'idx_movements_branch_type');
                }
            });
        }

        if (Schema::hasTable('communication_logs')) {
            Schema::table('communication_logs', function (Blueprint $table) {
                if (! $this->indexExists('communication_logs', 'idx_commlogs_customer_channel_date')) {
                    $table->index(['customer_id', 'channel', 'created_at'], 'idx_commlogs_customer_channel_date');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sales')) {
            Schema::table('sales', function (Blueprint $table) {
                if ($this->indexExists('sales', 'idx_sales_customer_status')) $table->dropIndex('idx_sales_customer_status');
                if ($this->indexExists('sales', 'idx_sales_date'))            $table->dropIndex('idx_sales_date');
            });
        }
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if ($this->indexExists('stock_movements', 'idx_movements_branch_type')) $table->dropIndex('idx_movements_branch_type');
            });
        }
        if (Schema::hasTable('communication_logs')) {
            Schema::table('communication_logs', function (Blueprint $table) {
                if ($this->indexExists('communication_logs', 'idx_commlogs_customer_channel_date')) $table->dropIndex('idx_commlogs_customer_channel_date');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);
        return count($indexes) > 0;
    }
};
