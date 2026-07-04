<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_group_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->foreignId('customer_group_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestamps();

            $table->foreign('variant_id')
                ->references('id')->on('product_variants')
                ->nullOnDelete();

            $table->unique(
                ['product_id', 'variant_id', 'customer_group_id'],
                'pgp_product_variant_group'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_prices');
    }
};
