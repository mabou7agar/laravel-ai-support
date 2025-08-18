<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id')->unique()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('metadata')->nullable();
            $table->json('settings')->nullable(); // Max messages, temperature, etc.
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'is_active']);
            $table->index(['conversation_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
