<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create pivot table
        Schema::create('branch_warehouse', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->primary(['branch_id', 'warehouse_id']);
            $table->index('warehouse_id');
        });

        // 2. Migrate any existing branch_id links into the pivot
        if (Schema::hasColumn('warehouses', 'branch_id')) {
            DB::statement(
                'INSERT IGNORE INTO branch_warehouse (branch_id, warehouse_id)
                 SELECT branch_id, id FROM warehouses WHERE branch_id IS NOT NULL'
            );

            // 3. Drop the old single-branch column
            Schema::table('warehouses', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }

    public function down(): void
    {
        // Restore branch_id as nullable from the pivot (best-effort: use first linked branch)
        Schema::table('warehouses', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id')->nullable()->after('store_id');
        });

        DB::statement(
            'UPDATE warehouses w
             SET branch_id = (
                 SELECT branch_id FROM branch_warehouse bw
                 WHERE bw.warehouse_id = w.id
                 LIMIT 1
             )'
        );

        Schema::dropIfExists('branch_warehouse');
    }
};
