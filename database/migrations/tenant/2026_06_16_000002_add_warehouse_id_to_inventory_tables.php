<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. inventory_items: make branch_id nullable, add warehouse_id, fix unique
        Schema::table('inventory_items', function (Blueprint $table) {
            // Add standalone product_id index so MySQL can release its use of
            // inv_product_variant_branch as the backing index for the product_id FK.
            $table->index('product_id', 'inv_product_id_plain');
        });
        Schema::table('inventory_items', function (Blueprint $table) {
            // Now safe to drop the old composite unique constraint
            $table->dropUnique('inv_product_variant_branch');
            // Make branch_id nullable (warehouses won't have one)
            $table->unsignedBigInteger('branch_id')->nullable()->change();
            // New warehouse_id column
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('branch_id');
            // New unique: one stock record per product+variant per location
            // MySQL treats NULLs as distinct so (prod, var, branch=1, wh=null) ≠ (prod, var, branch=null, wh=1)
            $table->unique(
                ['product_id', 'variant_id', 'branch_id', 'warehouse_id'],
                'inv_product_variant_location'
            );
            $table->index('warehouse_id', 'inv_warehouse_id');
        });

        // 2. stock_movements: add warehouse_id for movement audit at warehouse level
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('branch_id');
            $table->index('warehouse_id', 'sm_warehouse_id');
        });

        // 3. stock_transfers: add warehouse source/destination columns
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('from_warehouse_id')->nullable()->after('from_branch_id');
            $table->unsignedBigInteger('to_warehouse_id')->nullable()->after('to_branch_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn(['from_warehouse_id', 'to_warehouse_id']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('sm_warehouse_id');
            $table->dropColumn('warehouse_id');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropUnique('inv_product_variant_location');
            $table->dropIndex('inv_warehouse_id');
            $table->dropColumn('warehouse_id');
            $table->unsignedBigInteger('branch_id')->nullable(false)->change();
            $table->unique(['product_id', 'variant_id', 'branch_id'], 'inv_product_variant_branch');
        });
    }
};
