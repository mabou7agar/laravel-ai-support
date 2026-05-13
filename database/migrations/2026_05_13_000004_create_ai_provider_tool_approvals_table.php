<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_tool_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('approval_key')->unique();
            $table->foreignId('tool_run_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('tool_name')->index();
            $table->string('risk_level')->default('medium')->index();
            $table->string('status')->default('pending')->index();
            $table->string('requested_by')->nullable()->index();
            $table->string('resolved_by')->nullable()->index();
            $table->json('tool_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['tool_run_id', 'tool_name', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_tool_approvals');
    }
};
