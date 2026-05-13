<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_tool_audit_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tool_run_id')->nullable()->index();
            $table->foreignId('approval_id')->nullable()->index();
            $table->string('event')->index();
            $table->string('provider')->nullable()->index();
            $table->string('tool_name')->nullable()->index();
            $table->string('actor_id')->nullable()->index();
            $table->json('payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_tool_audit_events');
    }
};
