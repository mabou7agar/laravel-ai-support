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
        Schema::create('ai_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->enum('type', ['master', 'child'])->default('child');
            $table->string('url'); // Base URL
            
            // Authentication
            $table->string('api_key', 64)->nullable()->unique();
            $table->string('refresh_token', 64)->nullable()->unique();
            $table->timestamp('refresh_token_expires_at')->nullable();
            
            // Metadata
            $table->json('capabilities')->nullable(); // ['search', 'actions', 'rag']
            $table->json('metadata')->nullable(); // Custom data (domains, data_types, etc.)
            $table->string('version')->nullable();
            
            // Status & Health
            $table->enum('status', ['active', 'inactive', 'maintenance', 'error'])->default('active');
            $table->timestamp('last_ping_at')->nullable();
            $table->integer('ping_failures')->default(0);
            $table->integer('avg_response_time')->nullable(); // milliseconds
            
            // Load Balancing
            $table->integer('weight')->default(1); // For weighted load balancing
            $table->integer('active_connections')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index(['status', 'type']);
            $table->index('last_ping_at');
            $table->index('slug');
            $table->index('api_key');
            $table->index('refresh_token');
            $table->index(['status', 'avg_response_time']); // For load balancing
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_nodes');
    }
};
