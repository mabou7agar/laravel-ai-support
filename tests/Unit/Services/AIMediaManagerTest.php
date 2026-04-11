<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Models\AIMedia;
use LaravelAIEngine\Services\AIMediaManager;
use LaravelAIEngine\Tests\TestCase;

class AIMediaManagerTest extends TestCase
{
    public function test_store_binary_persists_ai_media_record_and_file_on_configured_disk(): void
    {
        Storage::fake('public');
        config()->set('ai-engine.media_library.disk', 'public');

        $stored = app(AIMediaManager::class)->storeBinary(
            'fake-image-bytes',
            'hero.png',
            [
                'engine' => 'fal_ai',
                'ai_model' => 'fal-ai/nano-banana-2',
                'content_type' => 'image',
                'collection_name' => 'generated-images',
                'mime_type' => 'image/png',
                'name' => 'hero',
            ]
        );

        $this->assertNotNull($stored['id']);
        $this->assertNotNull($stored['url']);
        Storage::disk('public')->assertExists($stored['path']);

        $media = AIMedia::query()->find($stored['id']);
        $this->assertNotNull($media);
        $this->assertSame('fal_ai', $media->engine);
        $this->assertSame('fal-ai/nano-banana-2', $media->ai_model);
        $this->assertSame('image', $media->content_type);
        $this->assertSame('generated-images', $media->collection_name);
    }

    public function test_disabled_media_library_falls_back_without_persisting_record(): void
    {
        config()->set('ai-engine.media_library.enabled', false);

        $stored = app(AIMediaManager::class)->storeRemoteFile('https://example.com/image.png', [
            'engine' => 'fal_ai',
            'content_type' => 'image',
        ]);

        $this->assertNull($stored['id']);
        $this->assertSame('https://example.com/image.png', $stored['url']);
        $this->assertDatabaseCount('ai_media', 0);
    }
}
