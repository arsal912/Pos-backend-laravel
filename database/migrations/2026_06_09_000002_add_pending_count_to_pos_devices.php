<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Central DB — track how many offline sales are pending on each device. */
    public function up(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->unsignedInteger('pending_sales_count')->default(0)->after('last_sync_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_devices', function (Blueprint $table) {
            $table->dropColumn('pending_sales_count');
        });
    }
};
