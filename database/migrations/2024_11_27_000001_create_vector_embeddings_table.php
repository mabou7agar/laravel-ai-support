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
        Schema::create('vector_embeddings', function (Blueprint $table) {
            $table->id();
            $table->string('model_type')->index();
            $table->unsignedBigInteger('model_id')->index();
            $table->string('collection_name')->index();
            $table->string('vector_id')->unique();
            $table->text('content')->nullable();
            $table->json('metadata')->nullable();
            $table->string('embedding_model')->default('text-embedding-3-large');
            $table->integer('embedding_dimensions')->default(3072);
            $table->string('status')->default('indexed'); // indexed, pending, failed
            $table->timestamp('indexed_at')->nullable();
            $table->timestamps();

            // Composite index for model lookups
            $table->index(['model_type', 'model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_embeddings');
    }
};
