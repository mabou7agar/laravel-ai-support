<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_tool_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider')->index();
            $table->string('engine')->nullable()->index();
            $table->string('ai_model')->nullable()->index();
            $table->string('status')->default('created')->index();
            $table->string('request_id')->nullable()->index();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('conversation_id')->nullable()->index();
            $table->string('user_id')->nullable()->index();
            $table->json('tool_names')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->json('continuation_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('awaiting_approval_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status']);
            $table->index(['provider', 'ai_model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_tool_runs');
    }
};
