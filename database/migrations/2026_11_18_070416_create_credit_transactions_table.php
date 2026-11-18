<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // purchase | hold | release | charge
            $table->string('type');
            // Signed picodollars: purchase and release are positive, hold and charge negative.
            $table->bigInteger('amount_pico');
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            // A generation has at most one hold, one release and one charge. The database
            // enforces this, so a bug can never double-charge. (NULLs, i.e. purchases, don't collide.)
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
