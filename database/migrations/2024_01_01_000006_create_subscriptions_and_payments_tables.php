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
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
