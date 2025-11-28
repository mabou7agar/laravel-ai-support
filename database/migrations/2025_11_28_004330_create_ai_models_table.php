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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->string('provider'); // openai, anthropic, google, etc.
            $table->string('model_id')->unique(); // gpt-4o, gpt-5, claude-3-5-sonnet
            $table->string('name'); // GPT-4o, GPT-5, Claude 3.5 Sonnet
            $table->string('version')->nullable(); // 2024-11-20, v1.0
            $table->text('description')->nullable();
            $table->json('capabilities')->nullable(); // ['chat', 'vision', 'function_calling']
            $table->json('context_window')->nullable(); // ['input': 128000, 'output': 4096]
            $table->json('pricing')->nullable(); // ['input': 0.01, 'output': 0.03]
            $table->integer('max_tokens')->nullable();
            $table->boolean('supports_streaming')->default(true);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_function_calling')->default(false);
            $table->boolean('supports_json_mode')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_deprecated')->default(false);
            $table->timestamp('released_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->json('metadata')->nullable(); // Additional flexible data
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['provider', 'is_active']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
