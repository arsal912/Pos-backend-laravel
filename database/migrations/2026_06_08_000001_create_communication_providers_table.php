<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_providers', function (Blueprint $table) {
            $table->id();
            $table->enum('channel', ['sms', 'email', 'whatsapp']);
            $table->string('provider_slug');       // twilio-sms | twilio-whatsapp | resend | local-pk-sms
            $table->string('name');                // Display name
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default_for_channel')->default(false);
            $table->json('credentials')->nullable();  // encrypted
            $table->json('config')->nullable();        // non-secret settings
            $table->json('rate_limits')->nullable();   // provider limits
            $table->string('test_recipient')->nullable(); // phone/email for test sends
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['channel', 'provider_slug'], 'comm_prov_channel_slug');
            $table->index(['channel', 'is_active', 'is_default_for_channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_providers');
    }
};
