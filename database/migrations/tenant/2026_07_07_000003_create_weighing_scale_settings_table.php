<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weighing_scale_settings', function (Blueprint $table) {
            $table->id();
            $table->string('default_weight_unit', 2)->default('kg');
            // Only "manual" is supported today. "serial" is reserved for a future
            // Web Serial/USB hardware integration that does NOT exist yet — see
            // WeighingScaleSettingController::update() for the validation guard.
            $table->string('connection_mode')->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weighing_scale_settings');
    }
};
