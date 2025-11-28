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
        Schema::create('vector_search_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('model_type')->index();
            $table->text('query');
            $table->integer('results_count')->default(0);
            $table->integer('limit')->default(20);
            $table->float('threshold')->default(0.3);
            $table->json('filters')->nullable();
            $table->float('execution_time')->nullable(); // milliseconds
            $table->integer('tokens_used')->default(0);
            $table->string('status')->default('success'); // success, failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Index for analytics
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_search_logs');
    }
};
