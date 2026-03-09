<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_action_metrics')) {
            return;
        }

        Schema::create('ai_action_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('action_id')->index();
            $table->string('user_id')->nullable()->index();
            $table->boolean('success')->default(false)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_action_metrics');
    }
};

