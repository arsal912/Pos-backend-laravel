<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('report_slug');
            $table->json('filters')->nullable();
            $table->string('schedule');          // cron expression or 'daily','weekly','monthly'
            $table->json('recipient_emails');    // array of emails
            $table->json('formats')->default(json_encode(['pdf']));  // ['pdf','excel','csv']
            $table->timestamp('last_sent_at')->nullable();
            $table->string('last_status')->nullable();   // 'success','error','pending'
            $table->text('last_error')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'report_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
    }
};
