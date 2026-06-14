<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_report_requests', function (Blueprint $table) {
            $table->id();
            $table->string('from_number', 30);
            $table->text('message');
            $table->enum('status', ['pending','processing','completed','failed'])->default('pending');
            $table->string('report_type', 50)->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('period_label', 100)->nullable();
            $table->string('pdf_path', 500)->nullable();
            $table->string('download_token', 64)->nullable()->unique();
            $table->timestamp('download_expires_at')->nullable();
            $table->json('ai_response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['from_number', 'created_at']);
            $table->index('download_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_report_requests');
    }
};
