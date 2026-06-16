<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_inventory_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            $table->string('store_name', 150);
            $table->enum('location_type', ['branch', 'warehouse']);
            $table->unsignedBigInteger('location_id');
            $table->string('location_name', 150);
            $table->string('product_sku', 100);
            $table->string('product_name', 255);
            $table->decimal('quantity', 15, 3)->default(0);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['store_id', 'location_type', 'location_id', 'product_sku'],
                'snap_store_loc_product'
            );
            $table->index(['store_id']);
            $table->index(['product_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_inventory_snapshots');
    }
};
