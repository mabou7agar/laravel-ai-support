<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_prompt_policy_versions', function (Blueprint $table) {
            $table->id();
            $table->string('policy_key')->default('decision')->index();
            $table->unsignedInteger('version');
            $table->string('status')->default('draft')->index();
            $table->string('scope_key')->default('global')->index();
            $table->string('name')->nullable();
            $table->longText('template');
            $table->json('rules')->nullable();
            $table->json('target_context')->nullable();
            $table->unsignedTinyInteger('rollout_percentage')->default(0);
            $table->json('metrics')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('promoted_from_id')->nullable()->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_key', 'version']);
            $table->index(['policy_key', 'status', 'scope_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_prompt_policy_versions');
    }
};
