<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            // Delivery tracking
            $table->string('provider_message_id')->nullable()->after('provider');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('opened_at')->nullable()->after('delivered_at');
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
            $table->decimal('cost', 10, 4)->nullable()->after('clicked_at');

            // Retry tracking
            $table->unsignedSmallInteger('retry_count')->default(0)->after('error_message');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');

            // Campaign link (populated in Step 5)
            $table->unsignedBigInteger('campaign_id')->nullable()->after('reference_id');

            $table->index('provider_message_id');
            $table->index(['channel', 'delivered_at']);
        });

        // Extend type enum to include otp + notification
        // MySQL: modify enum adds new values
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->enum('type', [
                'transactional', 'marketing', 'reminder',
                'birthday', 'manual', 'otp', 'notification',
            ])->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->dropColumn([
                'provider_message_id', 'delivered_at', 'opened_at',
                'clicked_at', 'cost', 'retry_count', 'next_retry_at',
                'campaign_id',
            ]);
        });
    }
};
