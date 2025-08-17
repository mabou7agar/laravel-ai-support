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
        Schema::create('ai_job_statuses', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->unique()->index();
            $table->enum('status', ['queued', 'processing', 'completed', 'failed'])->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            // Add indexes for common queries
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_job_statuses');
    }
};
