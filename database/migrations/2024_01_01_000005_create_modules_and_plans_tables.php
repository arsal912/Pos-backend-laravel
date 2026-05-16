<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category');         // core, products, inventory, etc.
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_core')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'is_active']);
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');
            $table->enum('billing_cycle', ['monthly', 'yearly', 'lifetime'])->default('monthly');
            $table->integer('trial_days')->default(0);

            // Limits
            $table->integer('max_products')->nullable();
            $table->integer('max_users')->nullable();
            $table->integer('max_branches')->nullable();
            $table->integer('max_transactions_per_month')->nullable();

            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'module_id']);
        });

        Schema::create('store_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['store_id', 'module_id']);
            $table->index(['store_id', 'is_enabled']);
        });

        Schema::create('user_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->foreignId('overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'module_id']);
            $table->index(['user_id', 'is_enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_modules');
        Schema::dropIfExists('store_modules');
        Schema::dropIfExists('plan_modules');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('modules');
    }
};
