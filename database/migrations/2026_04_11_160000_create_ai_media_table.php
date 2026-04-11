<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->nullableMorphs('model');
            $table->string('user_id')->nullable()->index();
            $table->string('request_id')->nullable()->index();
            $table->string('provider_request_id')->nullable()->index();
            $table->string('engine')->nullable()->index();
            $table->string('ai_model')->nullable()->index();
            $table->string('content_type')->nullable()->index();
            $table->string('collection_name')->default('default')->index();
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk')->index();
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('path');
            $table->text('url')->nullable();
            $table->text('source_url')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->decimal('duration', 10, 2)->nullable();
            $table->json('manipulations')->nullable();
            $table->json('custom_properties')->nullable();
            $table->json('generated_conversions')->nullable();
            $table->json('responsive_images')->nullable();
            $table->unsignedInteger('order_column')->nullable();
            $table->timestamps();

            $table->index(['engine', 'ai_model']);
            $table->index(['content_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_media');
    }
};
