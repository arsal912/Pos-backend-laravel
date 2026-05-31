<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'warning_sent_7d')) {
                $table->timestamp('warning_sent_7d')->nullable()->after('next_billing_at');
            }
            if (! Schema::hasColumn('subscriptions', 'warning_sent_3d')) {
                $table->timestamp('warning_sent_3d')->nullable()->after('warning_sent_7d');
            }
            if (! Schema::hasColumn('subscriptions', 'warning_sent_1d')) {
                $table->timestamp('warning_sent_1d')->nullable()->after('warning_sent_3d');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            foreach (['warning_sent_7d', 'warning_sent_3d', 'warning_sent_1d'] as $col) {
                if (Schema::hasColumn('subscriptions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
