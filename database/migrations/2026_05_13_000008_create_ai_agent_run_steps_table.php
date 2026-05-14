<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_run_steps', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('run_id')->index();
            $table->unsignedInteger('sequence')->default(1);
            $table->string('step_key')->nullable()->index();
            $table->string('type')->default('routing')->index();
            $table->string('status')->default('pending')->index();
            $table->string('action')->nullable()->index();
            $table->string('source')->nullable()->index();
            $table->foreignId('provider_tool_run_id')->nullable()->index();
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->json('routing_decision')->nullable();
            $table->json('routing_trace')->nullable();
            $table->json('approvals')->nullable();
            $table->json('artifacts')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'sequence']);
            $table->index(['run_id', 'status']);
            $table->index(['run_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_run_steps');
    }
};
