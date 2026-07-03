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
        });
    }

    public function down(): void
    {
        // Foreign key removal is handled implicitly when the table is modified
        // Explicit removal can cause issues if constraints don't exist
        // This migration is primarily for adding foreign keys that should persist
    }
};
