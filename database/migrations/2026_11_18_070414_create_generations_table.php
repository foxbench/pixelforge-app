<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Our own id: sent as the Idempotency-Key and as metadata.local_id.
            $table->uuid('uuid')->unique();
            // Pixelforge's id (gen_...). Null until we know it; unique once set.
            $table->string('provider_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // pending_submit | queued | processing | succeeded | failed
            $table->string('status')->default('pending_submit')->index();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // All money columns are integer picodollars (1 USD = 10^12).
            $table->bigInteger('estimated_max_charge_pico');
            $table->bigInteger('estimated_typical_charge_pico');
            $table->json('usage')->nullable();
            $table->json('rates')->nullable();
            $table->bigInteger('provider_cost_pico')->nullable();
            $table->bigInteger('charge_pico')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            // The provider's completion time; the admin report groups by its UTC day.
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
