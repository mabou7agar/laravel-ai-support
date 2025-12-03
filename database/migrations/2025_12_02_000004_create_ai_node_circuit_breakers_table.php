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
        Schema::create('ai_node_circuit_breakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('ai_nodes')->onDelete('cascade');
            
            // Circuit breaker state
            $table->enum('state', ['closed', 'open', 'half_open'])->default('closed');
            $table->integer('failure_count')->default(0);
            $table->integer('success_count')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->unique('node_id');
            $table->index(['state', 'next_retry_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_node_circuit_breakers');
    }
};
