<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_aggregates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('tenant_database')->nullable();
            $table->unsignedInteger('branches_count')->default(0);
            $table->unsignedInteger('subscriptions_count')->default(0);
            $table->unsignedInteger('payments_count')->default(0);
            $table->unsignedInteger('active_users_count')->default(0);
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->decimal('month_revenue', 14, 2)->default(0);
            $table->decimal('today_revenue', 14, 2)->default(0);
            $table->timestamp('last_payment_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique('store_id');
            $table->index('last_synced_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_aggregates');
    }
};
