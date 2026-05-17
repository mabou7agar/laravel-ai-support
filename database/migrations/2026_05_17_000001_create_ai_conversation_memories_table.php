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
            $table->string('memory_id')->unique();
            $table->string('namespace')->default('conversation');
            $table->string('key');
            $table->text('value')->nullable();
            $table->text('summary');
            $table->json('metadata')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('workspace_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->float('confidence')->default(0.7);
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['namespace', 'key', 'user_id', 'tenant_id', 'workspace_id', 'session_id'],
                'ai_conversation_memories_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_memories');
    }
};
