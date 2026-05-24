<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversation_memories', function (Blueprint $table): void {
            $table->id();
            $table->string('memory_id', 64)->unique();
            $table->string('namespace', 80)->default('conversation');
            $table->string('key', 191);
            $table->char('key_hash', 64);
            $table->char('scope_hash', 64);
            $table->text('value')->nullable();
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->string('scope_type', 80)->default('global');
            $table->string('scope_id', 191)->nullable();
            $table->string('session_id', 191)->nullable();
            $table->float('confidence')->default(0.7);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['namespace', 'key_hash', 'scope_hash'],
                'ai_conversation_memories_scope_unique'
            );
            $table->index(['namespace', 'scope_hash'], 'ai_conversation_memories_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_memories');
    }
};
