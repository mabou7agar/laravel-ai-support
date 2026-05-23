<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_learned_items', function (Blueprint $table): void {
            $table->id();
            $table->string('item_id')->unique();
            $table->foreignId('learn_source_id')->constrained('ai_learn_sources')->cascadeOnDelete();
            $table->string('kind')->default('section')->index();
            $table->string('title')->nullable();
            $table->longText('content');
            $table->json('metadata')->nullable();
            $table->float('confidence')->default(0.7);
            $table->unsignedInteger('position')->default(0);
            $table->string('user_id')->nullable()->index();
            $table->string('tenant_id')->nullable()->index();
            $table->string('workspace_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->timestamps();

            $table->index(['kind', 'tenant_id', 'workspace_id'], 'ai_learned_items_kind_scope_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_learned_items');
    }
};
