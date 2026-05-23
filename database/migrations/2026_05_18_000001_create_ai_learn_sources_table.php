<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_learn_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('source_id')->unique();
            $table->string('source_type')->index();
            $table->string('source')->index();
            $table->string('adapter')->nullable()->index();
            $table->string('type')->default('general')->index();
            $table->string('title')->nullable();
            $table->longText('content')->nullable();
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->string('user_id')->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('workspace_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('vector_store_id')->nullable()->index();
            $table->timestamp('indexed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['type', 'tenant_id', 'workspace_id'], 'ai_learn_sources_type_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learn_sources');
    }
};
