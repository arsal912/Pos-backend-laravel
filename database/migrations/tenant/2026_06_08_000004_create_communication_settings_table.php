<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row sender identity table per tenant.
        Schema::create('communication_settings', function (Blueprint $table) {
            $table->id();
            $table->string('sms_sender_id')->nullable();          // e.g. "MYSTORE" (11 chars)
            $table->string('email_from_address')->nullable();
            $table->string('email_from_name')->nullable();
            $table->string('whatsapp_business_number')->nullable();
            $table->text('store_physical_address')->nullable();    // Required for CAN-SPAM
            $table->string('unsubscribe_landing_url')->nullable(); // Auto-generated
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_settings');
    }
};
