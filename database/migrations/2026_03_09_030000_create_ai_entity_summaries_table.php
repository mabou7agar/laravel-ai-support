<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_entity_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('summaryable_type');
            $table->string('summaryable_id', 191);
            $table->string('locale', 16)->default('en');
            $table->text('summary');
            $table->string('source_hash', 64)->nullable();
            $table->string('policy_version', 32)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['summaryable_type', 'summaryable_id'], 'ai_entity_summaries_entity_idx');
            $table->index('expires_at', 'ai_entity_summaries_expires_idx');
            $table->unique(
                ['summaryable_type', 'summaryable_id', 'locale'],
                'ai_entity_summaries_entity_locale_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_entity_summaries');
    }
};

