<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inter_store_transfer_requests', function (Blueprint $table) {
            $table->id();

            // The store making the request
            $table->unsignedBigInteger('requesting_store_id');
            $table->string('requesting_store_name', 150);
            $table->unsignedBigInteger('requesting_user_id');

            // The store that holds the stock
            $table->unsignedBigInteger('source_store_id');
            $table->string('source_store_name', 150);

            // Location within the source store
            $table->enum('source_location_type', ['branch', 'warehouse']);
            $table->unsignedBigInteger('source_location_id');
            $table->string('source_location_name', 150);

            // Product (SKU is the cross-store identifier)
            $table->string('product_sku', 100);
            $table->string('product_name', 255);

            // Quantities
            $table->decimal('quantity_requested', 15, 3);
            $table->decimal('quantity_fulfilled', 15, 3)->nullable();

            // Workflow
            $table->enum('status', [
                'pending',      // waiting for source store approval
                'approved',     // source store approved, awaiting dispatch
                'in_transit',   // stock dispatched by source store
                'completed',    // received and confirmed by requesting store
                'rejected',     // source store rejected
                'cancelled',    // requesting store cancelled
            ])->default('pending');

            $table->text('request_notes')->nullable();
            $table->text('response_notes')->nullable();
            $table->unsignedBigInteger('actioned_by_user_id')->nullable();
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index(['requesting_store_id', 'status']);
            $table->index(['source_store_id', 'status']);
            $table->foreign('requesting_store_id')->references('id')->on('stores')->cascadeOnDelete();
            $table->foreign('source_store_id')->references('id')->on('stores')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_store_transfer_requests');
    }
};
