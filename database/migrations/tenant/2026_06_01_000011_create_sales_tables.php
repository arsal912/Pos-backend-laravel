<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── Sales ─────────────────────────────────────────────────────────────
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_number')->unique();     // S-{YYYY}-{8digit}
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('cashier_id');   // plain int — user from central
            $table->date('sale_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->enum('discount_type', ['fixed', 'percent'])->nullable();
            $table->string('discount_reason')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('change_given', 15, 2)->default(0);
            $table->decimal('balance', 15, 2)->default(0); // negative = store credit owed
            $table->enum('status', ['draft', 'completed', 'refunded', 'partially_refunded', 'cancelled'])
                ->default('draft');
            $table->enum('payment_status', ['pending', 'paid', 'partial', 'refunded'])
                ->default('pending');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['branch_id', 'sale_date', 'status']);
            $table->index(['customer_id', 'sale_date']);
            $table->index(['cashier_id', 'sale_date']);
        });

        // ─── Sale Items ──────────────────────────────────────────────────────────
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->string('product_name');          // denormalized for receipt
            $table->string('sku');                   // denormalized
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('cost_at_time', 15, 2)->default(0); // for COGS
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();

            $table->index(['sale_id', 'product_id']);
        });

        // ─── Sale Payments ────────────────────────────────────────────────────────
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->enum('method', [
                'cash', 'card', 'bank_transfer', 'jazzcash',
                'easypaisa', 'store_credit', 'other',
            ])->default('cash');
            $table->decimal('amount', 15, 2);
            $table->string('reference')->nullable(); // card last 4, txn ID
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // ─── Sale Returns ─────────────────────────────────────────────────────────
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_number')->unique();   // RET-{YYYY}-{6digit}
            $table->foreignId('original_sale_id')->constrained('sales')->restrictOnDelete();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('cashier_id');
            $table->date('return_date');
            $table->decimal('refund_amount', 15, 2);
            $table->enum('refund_method', [
                'cash', 'card', 'bank_transfer', 'jazzcash',
                'easypaisa', 'store_credit', 'other',
            ])->default('cash');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->enum('status', ['pending', 'completed'])->default('completed');
            $table->timestamps();
            $table->softDeletes();
        });

        // ─── Sale Return Items ────────────────────────────────────────────────────
        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('variant_id')->nullable();
            $table->decimal('quantity_returned', 15, 3);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('refund_amount', 15, 2);
            $table->boolean('restock')->default(true); // put back into inventory
            $table->timestamps();
        });

        // ─── Cash Drawer Sessions ─────────────────────────────────────────────────
        Schema::create('cash_drawer_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cashier_id');
            $table->timestamp('opened_at');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->decimal('expected_balance', 15, 2)->nullable();
            $table->decimal('over_short', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'cashier_id', 'closed_at']);
        });

        // ─── Hold Sales (parked carts) ────────────────────────────────────────────
        Schema::create('hold_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('cashier_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('name');                  // "Table 5" or customer name
            $table->json('data');                    // full cart state
            $table->timestamps();

            $table->index(['branch_id', 'cashier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hold_sales');
        Schema::dropIfExists('cash_drawer_sessions');
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('sale_payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
