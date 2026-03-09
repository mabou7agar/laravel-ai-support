<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_feedback_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('rag_decision')->index();
            $table->string('event_type')->index();

            $table->string('policy_key')->nullable()->index();
            $table->unsignedBigInteger('policy_id')->nullable()->index();
            $table->unsignedInteger('policy_version')->nullable();
            $table->string('policy_status')->nullable();

            $table->string('session_id')->nullable()->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('app_id')->nullable()->index();

            $table->text('request_text')->nullable();
            $table->text('message_excerpt')->nullable();
            $table->text('raw_response_excerpt')->nullable();

            $table->string('decision_tool')->nullable()->index();
            $table->string('decision_source')->nullable()->index();
            $table->text('reasoning')->nullable();
            $table->json('decision_parameters')->nullable();
            $table->json('tool_calls')->nullable();

            $table->boolean('relist_risk')->default(false)->index();
            $table->boolean('success')->nullable()->index();
            $table->string('outcome')->nullable()->index();

            $table->integer('latency_ms')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('token_cost', 12, 6)->nullable();
            $table->smallInteger('user_rating')->nullable()->index();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
            $table->index(['policy_key', 'policy_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_feedback_events');
    }
};
