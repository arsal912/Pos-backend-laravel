<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Reference to the offline-generated number (e.g. OFF-A3B7C2-000001)
            $table->string('offline_reference', 50)->nullable()->after('notes')->index();

            // ID of the pos_devices record that created this sale offline
            // Intentionally no FK — pos_devices is in central DB, sales in tenant DB
            $table->unsignedBigInteger('synced_from_device_id')->nullable()->after('offline_reference');

            $table->timestamp('synced_at')->nullable()->after('synced_from_device_id');

            // Conflict flags — set when sale synced but server found stock / credit issues.
            // Sale is STILL created; these flags trigger review workflow.
            $table->boolean('has_stock_conflict')->default(false)->after('synced_at');
            $table->boolean('has_credit_conflict')->default(false)->after('has_stock_conflict');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['offline_reference']);
            $table->dropColumn([
                'offline_reference', 'synced_from_device_id', 'synced_at',
                'has_stock_conflict', 'has_credit_conflict',
            ]);
        });
    }
};
