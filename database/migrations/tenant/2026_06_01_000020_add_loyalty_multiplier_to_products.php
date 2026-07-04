<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Multiply the base earn rate for this product.
            // 1.0 = standard, 2.0 = double points, 0 = no points on this product.
            $table->decimal('loyalty_points_multiplier', 5, 2)
                ->default(1.00)
                ->after('allow_negative_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'loyalty_points_multiplier')) {
                $table->dropColumn('loyalty_points_multiplier');
            }
        });
    }
};
