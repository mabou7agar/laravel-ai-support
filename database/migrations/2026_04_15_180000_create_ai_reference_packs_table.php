<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reference_packs', function (Blueprint $table) {
            $table->id();
            $table->string('alias')->unique();
            $table->string('name')->nullable()->index();
            $table->string('entity_type')->nullable()->index();
            $table->text('frontal_image_url')->nullable();
            $table->text('frontal_provider_image_url')->nullable();
            $table->string('voice_id')->nullable()->index();
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reference_packs');
    }
};
