<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable()->index();
            $table->string('engine')->index();
            $table->string('model')->index();
            $table->string('content_type')->index();
            $table->integer('prompt_length');
            $table->integer('tokens_used')->nullable();
            $table->decimal('credits_used', 10, 4)->nullable();
            $table->decimal('latency_ms', 8, 2)->nullable();
            $table->boolean('cached')->default(false)->index();
            $table->boolean('success')->default(true)->index();
            $table->string('request_id')->nullable();
            $table->string('finish_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['engine', 'model']);
            $table->index(['user_id', 'created_at']);
            $table->index(['created_at', 'success']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
