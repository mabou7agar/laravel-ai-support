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
        Schema::create('ai_node_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('ai_nodes')->onDelete('cascade');
            
            // Request details
            $table->string('request_type'); // 'search', 'action', 'sync', 'ping'
            $table->string('trace_id', 32)->nullable(); // For distributed tracing
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            
            // Response details
            $table->integer('status_code')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            
            // Metadata
            $table->string('user_agent')->nullable();
            $table->ipAddress('ip_address')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index(['node_id', 'created_at']);
            $table->index(['request_type', 'status']);
            $table->index('trace_id');
            $table->index('created_at');
            $table->index(['node_id', 'status', 'created_at']); // For metrics
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_node_requests');
    }
};
