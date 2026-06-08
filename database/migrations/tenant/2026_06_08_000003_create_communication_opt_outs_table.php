<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->enum('channel', ['sms', 'email', 'whatsapp']);
            $table->string('recipient');      // normalised phone or email
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('reason')->nullable();
            $table->timestamp('opted_out_at');
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->unique(['channel', 'recipient'], 'opt_out_channel_recipient');
            $table->index('recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_opt_outs');
    }
};
