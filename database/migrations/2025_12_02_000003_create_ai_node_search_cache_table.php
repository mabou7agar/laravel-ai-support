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
        Schema::create('ai_node_search_cache', function (Blueprint $table) {
            $table->id();
            $table->string('query_hash', 64)->unique();
            $table->text('query');
            $table->json('node_ids'); // Which nodes were searched
            $table->longText('results'); // JSON results
            $table->integer('result_count')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->timestamp('expires_at');
            $table->integer('hit_count')->default(0); // Track cache hits
            $table->timestamps();
            
            // Indexes
            $table->index('query_hash');
            $table->index('expires_at');
            $table->index(['expires_at', 'hit_count']); // For cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_node_search_cache');
    }
};
