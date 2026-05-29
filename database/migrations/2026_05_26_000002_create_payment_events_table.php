<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('gateway');
            $table->json('data')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
