<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_tool_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tool_run_id')->nullable()->index();
            $table->foreignId('media_id')->nullable()->index();
            $table->string('provider')->index();
            $table->string('artifact_type')->index();
            $table->string('name')->nullable();
            $table->string('mime_type')->nullable();
            $table->text('source_url')->nullable();
            $table->text('download_url')->nullable();
            $table->string('provider_file_id')->nullable()->index();
            $table->string('provider_container_id')->nullable()->index();
            $table->string('citation_title')->nullable();
            $table->text('citation_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['provider', 'artifact_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_tool_artifacts');
    }
};
