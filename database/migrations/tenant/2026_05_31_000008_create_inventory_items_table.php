<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            // branch_id is a plain int — no FK to central DB (D1)
            $table->unsignedBigInteger('branch_id');
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('reserved_quantity', 15, 3)->default(0);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->unique(['product_id', 'variant_id', 'branch_id'], 'inv_product_variant_branch');
            $table->index(['branch_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
