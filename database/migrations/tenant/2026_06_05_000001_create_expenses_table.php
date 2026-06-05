<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('expense_date');
            $table->string('category');          // Rent, Utilities, Salary, Marketing, etc.
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash','card','bank_transfer','cheque','other'])
                ->default('cash');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['expense_date', 'category']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
