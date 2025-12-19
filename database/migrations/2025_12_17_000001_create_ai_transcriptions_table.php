<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_transcriptions', function (Blueprint $table) {
            $table->id();
            $table->morphs('transcribable'); // transcribable_type, transcribable_id
            $table->longText('content'); // The transcription text
            $table->string('language', 10)->nullable(); // e.g., 'en', 'ar'
            $table->string('engine')->default('openai'); // whisper, etc.
            $table->string('model')->nullable(); // whisper-1, etc.
            $table->integer('duration_seconds')->nullable(); // Audio/video duration
            $table->decimal('confidence', 5, 4)->nullable(); // Transcription confidence score
            $table->json('segments')->nullable(); // Timestamped segments if available
            $table->json('metadata')->nullable(); // Additional metadata
            $table->string('status')->default('completed'); // pending, processing, completed, failed
            $table->text('error')->nullable(); // Error message if failed
            $table->timestamps();

            // Indexes (morphs already creates index on transcribable_type + transcribable_id)
            $table->index('status');
            $table->index('language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_transcriptions');
    }
};
