<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'sale_on_credit', 'payment_received', 'refund_credit',
                'opening_balance', 'adjustment',
            ]);
            // positive = customer owes more, negative = paid down / credit applied
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->enum('payment_method', [
                'cash', 'card', 'bank_transfer', 'jazzcash',
                'easypaisa', 'store_credit', 'other',
            ])->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
