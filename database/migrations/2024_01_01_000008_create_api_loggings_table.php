<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_loggings', function (Blueprint $table) {
            $table->id();

            // Who & where
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            // Request details
            $table->string('method', 10);
            $table->text('endpoint');
            $table->string('route_name')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_payload')->nullable();

            // Response details
            $table->integer('response_status')->nullable();
            $table->json('response_body')->nullable();

            // Client info
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Performance
            $table->integer('duration_ms')->nullable();

            // Errors
            $table->text('exception')->nullable();
            $table->longText('stack_trace')->nullable();

            $table->timestamps();

            // Indexes for fast lookup & filtering
            $table->index(['user_id', 'created_at']);
            $table->index(['store_id', 'created_at']);
            $table->index(['response_status', 'created_at']);
            $table->index(['method', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_loggings');
    }
};
