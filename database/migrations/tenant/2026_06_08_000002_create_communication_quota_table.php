<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row table per tenant (like loyalty_settings).
        Schema::create('communication_quotas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sms_daily_quota')->default(100);
            $table->unsignedInteger('sms_sent_today')->default(0);
            $table->timestamp('sms_quota_resets_at')->nullable();

            $table->unsignedInteger('email_daily_quota')->default(1000);
            $table->unsignedInteger('email_sent_today')->default(0);
            $table->timestamp('email_quota_resets_at')->nullable();

            $table->unsignedInteger('whatsapp_daily_quota')->default(50);
            $table->unsignedInteger('whatsapp_sent_today')->default(0);
            $table->timestamp('whatsapp_quota_resets_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_quotas');
    }
};
