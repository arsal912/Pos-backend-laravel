<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_page_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->text('maintenance_message')->nullable();
            $table->string('site_title')->default('POS System');
            $table->text('site_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->string('og_image')->nullable();
            $table->string('favicon')->nullable();
            $table->string('logo')->nullable();
            $table->string('primary_color', 20)->default('#4F46E5');
            $table->string('redirect_when_disabled')->nullable();
            $table->timestamps();
        });

        Schema::create('landing_page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->constrained('landing_page_settings')->cascadeOnDelete();
            $table->string('section_key');     // hero, features, pricing, testimonials, faq, cta, footer
            $table->string('title')->nullable();
            $table->string('subtitle')->nullable();
            $table->json('content')->nullable();   // flexible content per section
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['setting_id', 'section_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_page_sections');
        Schema::dropIfExists('landing_page_settings');
    }
};
