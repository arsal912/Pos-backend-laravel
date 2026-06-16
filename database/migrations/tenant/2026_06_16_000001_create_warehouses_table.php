<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('store_id');
            // Optional link to a retail branch (warehouse can serve that branch)
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->enum('type', ['storage', 'distribution', 'cold_storage', 'retail'])->default('storage');
            $table->text('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('manager', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
