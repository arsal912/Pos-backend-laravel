<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');           // e.g. "Piece", "Kg", "Litre"
            $table->string('short_code');     // e.g. "pc", "kg", "L"
            $table->boolean('is_decimal')->default(false);  // fractional qty allowed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
