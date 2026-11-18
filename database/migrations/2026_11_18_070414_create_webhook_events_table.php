<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // The unique index is the de-duplication: a second insert of the same event fails.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('generation_provider_id')->nullable()->index();
            $table->timestamp('received_at');
            $table->json('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
