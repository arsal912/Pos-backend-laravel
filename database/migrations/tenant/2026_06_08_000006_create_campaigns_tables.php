<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('channel', ['sms', 'email', 'whatsapp']);
            $table->enum('type', ['marketing', 'reminder', 'birthday', 'manual'])->default('marketing');

            // Optional: base the body on a saved template
            $table->unsignedBigInteger('message_template_id')->nullable();

            // Message content (may be a rendered copy of the template)
            $table->string('subject')->nullable();   // email only
            $table->text('body');

            // Static variables for template rendering (store_name, offer, etc.)
            $table->json('variables')->nullable();

            // Audience
            $table->enum('target_type', ['all_customers', 'customer_group', 'customer_segment'])->default('all_customers');
            $table->unsignedBigInteger('target_id')->nullable();  // group_id or segment_id

            // Scheduling
            $table->timestamp('scheduled_at')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'cancelled', 'failed'])->default('draft');

            // Stats (updated as DispatchCampaignJob runs)
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('channel');
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('recipient');              // phone or email
            $table->unsignedBigInteger('communication_log_id')->nullable();
            $table->timestamps();

            $table->foreign('campaign_id')->references('id')->on('campaigns')->cascadeOnDelete();
            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
