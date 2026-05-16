<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('business_type')->nullable();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('PK');
            $table->string('logo')->nullable();
            $table->string('currency', 10)->default('PKR');
            $table->string('timezone')->default('Asia/Karachi');

            $table->enum('status', ['pending', 'active', 'suspended', 'expired'])->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'is_active']);
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
