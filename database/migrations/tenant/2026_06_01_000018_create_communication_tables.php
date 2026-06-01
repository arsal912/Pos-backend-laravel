<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('recipient');        // phone or email
            $table->enum('channel', ['sms', 'email', 'whatsapp']);
            $table->enum('type', [
                'transactional', 'marketing', 'reminder',
                'birthday', 'manual',
            ])->default('manual');
            $table->string('subject')->nullable();   // email only
            $table->text('body');
            $table->enum('status', ['queued', 'sent', 'failed', 'skipped'])->default('queued');
            $table->string('provider')->nullable();  // 'twilio', 'logged_only', 'whatsapp_link'
            $table->json('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index(['customer_id', 'created_at']);
            $table->index(['channel', 'status', 'created_at']);
        });

        // Reusable message templates (Phase 5 integrates real providers)
        Schema::create('message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('channel', ['sms', 'email', 'whatsapp']);
            $table->string('subject')->nullable();
            $table->text('body');
            $table->enum('type', [
                'transactional', 'marketing', 'reminder', 'birthday', 'manual',
            ])->default('manual');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('communication_logs');
    }
};
