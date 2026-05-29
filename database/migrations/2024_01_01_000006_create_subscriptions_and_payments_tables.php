<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();         // stripe, paypal, jazzcash, easypaisa
            $table->string('logo')->nullable();
            $table->json('credentials')->nullable();  // encrypted
            $table->boolean('is_active')->default(false);
            $table->boolean('is_test_mode')->default(true);
            $table->boolean('supports_subscription')->default(false);
            $table->json('supported_currencies')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'active', 'expired', 'cancelled'])->default('pending');
            $table->string('payment_gateway')->nullable();
            $table->string('gateway_customer_id')->nullable();
            $table->string('gateway_subscription_id')->nullable();
            $table->string('billing_cycle')->default('monthly');
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('grace_period_ends_at')->nullable();
            $table->timestamp('next_billing_at')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('ends_at');
            $table->index('plan_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 10)->default('USD');
            $table->string('gateway');
            $table->string('gateway_payment_id')->nullable();
            $table->json('gateway_response')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed', 'refunded'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->string('invoice_number')->unique()->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('last_retry_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status']);
            $table->index('gateway');
            $table->index('subscription_id');
            $table->unique(['gateway', 'gateway_payment_id'], 'payments_gateway_gateway_payment_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payment_gateways');
    }
};
