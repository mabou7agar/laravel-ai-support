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
        Schema::create('ai_analytics_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id')->unique()->index();
            $table->string('user_id')->nullable()->index();
            $table->string('engine')->index();
            $table->string('model')->index();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->float('response_time')->default(0);
            $table->float('cost')->default(0);
            $table->boolean('success')->default(true)->index();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            
            // Indexes for analytics queries
            $table->index('created_at');
            $table->index(['engine', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['success', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_analytics_requests');
    }
};
