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
        Schema::create('ai_request_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->string('engine')->nullable();
            $table->string('model')->nullable();
            $table->integer('tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->integer('duration')->default(0); // milliseconds
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('created_at')->nullable();
            
            // Indexes for analytics queries
            $table->index(['engine', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_request_tracking');
    }
};
