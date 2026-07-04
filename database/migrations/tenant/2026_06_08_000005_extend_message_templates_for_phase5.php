<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            // Human-readable description of when to use this template
            $table->string('description')->nullable()->after('name');

            // JSON array of variable descriptors: [{"key":"customer_name","label":"Customer Name","example":"John"}]
            $table->json('variables')->nullable()->after('body');

            // Platform-seeded system templates are protected from deletion
            $table->boolean('is_system')->default(false)->after('is_active');

            // WhatsApp Business API approved template name (for Twilio content SID / template name)
            $table->string('whatsapp_template_name')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['description', 'variables', 'is_system', 'whatsapp_template_name']);
        });
    }
};
