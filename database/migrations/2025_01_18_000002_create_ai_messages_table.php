<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->string('message_id')->unique()->index();
            $table->string('conversation_id')->index();
            $table->enum('role', ['user', 'assistant', 'system'])->index();
            $table->longText('content');
            $table->json('metadata')->nullable(); // Tokens, model used, etc.
            $table->string('engine')->nullable();
            $table->string('model')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('credits_used', 10, 4)->nullable();
            $table->decimal('latency_ms', 10, 2)->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
            
            $table->foreign('conversation_id')->references('conversation_id')->on('ai_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'sent_at']);
            $table->index(['conversation_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};
