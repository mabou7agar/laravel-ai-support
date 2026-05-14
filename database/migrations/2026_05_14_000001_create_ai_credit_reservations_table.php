<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_credit_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('owner_id')->index();
            $table->string('engine')->index();
            $table->string('ai_model')->index();
            $table->decimal('amount', 14, 6);
            $table->string('status')->default('reserved')->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('request_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['engine', 'ai_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_credit_reservations');
    }
};
