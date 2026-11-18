<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // Submit retries: when the first and the latest attempt to send it to Pixelforge were,
            // and how many failures count toward the 6-attempt cap (see SubmitGeneration).
            $table->timestamp('first_submit_attempt_at')->nullable();
            $table->timestamp('last_submit_attempt_at')->nullable();
            $table->unsignedSmallInteger('submit_failures')->default(0);
            // How many polls in a row got a 404 for this generation (see PollGenerations).
            $table->unsignedSmallInteger('poll_not_found_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn(['first_submit_attempt_at', 'last_submit_attempt_at', 'submit_failures', 'poll_not_found_count']);
        });
    }
};
