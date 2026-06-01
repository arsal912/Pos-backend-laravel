<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row settings table (seeded on first access if empty)
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->decimal('points_per_currency_unit', 10, 4)->default(1.0);   // points earned per 1 currency unit spent
            $table->decimal('redemption_value', 10, 4)->default(1.0);           // currency value per 1 point when redeemed
            $table->decimal('minimum_points_to_redeem', 15, 2)->default(0);
            $table->decimal('maximum_redemption_per_sale', 5, 2)->nullable();   // % cap, e.g. 50 = 50% of sale
            $table->unsignedInteger('points_expiry_days')->nullable();           // null = never
            $table->boolean('earn_on_discounted_sales')->default(true);
            $table->boolean('earn_on_tax')->default(false);
            $table->decimal('welcome_bonus_points', 15, 2)->default(0);
            $table->decimal('birthday_bonus_points', 15, 2)->default(0);
            $table->decimal('referral_bonus_points', 15, 2)->default(0);
            $table->timestamps();
        });

        // Every points movement — earn, redeem, expire, adjust, bonus
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->enum('type', [
                'earn', 'redeem', 'expire', 'adjust_add', 'adjust_deduct',
                'welcome_bonus', 'birthday_bonus', 'referral_bonus', 'return_reversal',
            ]);
            $table->decimal('points', 15, 2);           // positive = add, negative = deduct
            $table->decimal('balance_after', 15, 2);    // denormalized snapshot
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('description');
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_settings');
    }
};
