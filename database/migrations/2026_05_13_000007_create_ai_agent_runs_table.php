<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('session_id')->index();
            $table->string('user_id')->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('workspace_id')->nullable()->index();
            $table->string('runtime')->default('laravel')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->json('input')->nullable();
            $table->json('final_response')->nullable();
            $table->string('current_step')->nullable()->index();
            $table->json('routing_trace')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('waiting_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
            $table->index(['tenant_id', 'workspace_id', 'status']);
            $table->index(['runtime', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_runs');
    }
};
