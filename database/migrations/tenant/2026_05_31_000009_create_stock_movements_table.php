<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->unsignedBigInteger('branch_id');
            $table->enum('type', [
                'sale', 'sale_return', 'purchase', 'purchase_return',
                'adjustment', 'transfer_out', 'transfer_in', 'initial',
            ]);
            $table->string('reference_type')->nullable();  // e.g. 'sale', 'purchase_order'
            $table->unsignedBigInteger('reference_id')->nullable();
            // positive = addition, negative = deduction
            $table->decimal('quantity', 15, 3);
            $table->decimal('cost_at_time', 15, 2)->default(0);
            $table->decimal('balance_after', 15, 3)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('variant_id')->references('id')->on('product_variants')->nullOnDelete();
            $table->index(['product_id', 'variant_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
