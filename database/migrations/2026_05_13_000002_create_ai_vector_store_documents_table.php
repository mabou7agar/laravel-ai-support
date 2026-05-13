<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_vector_store_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vector_store_id')->constrained('ai_vector_stores')->cascadeOnDelete();
            $table->string('document_id');
            $table->string('source');
            $table->string('disk')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['vector_store_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_vector_store_documents');
    }
};
