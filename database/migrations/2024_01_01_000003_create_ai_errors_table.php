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
        Schema::create('ai_errors', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->nullable()->index();
            $table->string('engine')->index();
            $table->string('model')->index();
            $table->string('content_type')->index();
            $table->string('error_type');
            $table->text('error_message');
            $table->integer('error_code')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['engine', 'model']);
            $table->index(['user_id', 'created_at']);
            $table->index(['error_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_errors');
    }
};
