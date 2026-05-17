<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'store_id')) {
                return;
            }

            // Add foreign keys if they don't already exist
            $table->foreign('store_id')->references('id')->on('stores')->nullOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            // drop foreign keys if exist
            try {
                $table->dropForeign(['store_id']);
            } catch (\Exception $e) {
            }

            try {
                $table->dropForeign(['branch_id']);
            } catch (\Exception $e) {
            }
        });
    }
};
