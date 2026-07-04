<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grn_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grn_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('quantity_received', 15, 3);
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->unsignedBigInteger('po_item_id')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->foreign('po_item_id')->references('id')->on('purchase_order_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grn_items');
    }
};
