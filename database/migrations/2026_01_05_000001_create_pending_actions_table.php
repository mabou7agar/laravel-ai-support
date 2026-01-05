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
        Schema::create('pending_actions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action_id');
            $table->string('action_type')->default('button');
            $table->string('label');
            $table->text('description')->nullable();
            $table->json('params');
            $table->json('missing_fields')->nullable();
            $table->json('suggested_params')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->boolean('is_executed')->default(false);
            $table->string('executor')->nullable();
            $table->string('model_class')->nullable();
            $table->string('node_slug')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['session_id', 'is_executed']);
            $table->index(['user_id', 'is_executed']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_actions');
    }
};
