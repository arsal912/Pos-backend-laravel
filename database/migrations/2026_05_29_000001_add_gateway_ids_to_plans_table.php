<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'stripe_price_id')) {
                $table->string('stripe_price_id')->nullable()->after('sort_order');
            }
            if (! Schema::hasColumn('plans', 'stripe_product_id')) {
                $table->string('stripe_product_id')->nullable()->after('stripe_price_id');
            }
            if (! Schema::hasColumn('plans', 'paypal_plan_id')) {
                $table->string('paypal_plan_id')->nullable()->after('stripe_product_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            foreach (['stripe_price_id', 'stripe_product_id', 'paypal_plan_id'] as $col) {
                if (Schema::hasColumn('plans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
