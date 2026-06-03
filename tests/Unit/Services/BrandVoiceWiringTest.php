<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Services\BrandVoiceManager;
use LaravelAIEngine\Services\Media\GenerateApiRequestFactory;
use LaravelAIEngine\Tests\TestCase;

class BrandVoiceWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_brand_voice_manager_is_resolvable_from_container()
    {
        $manager = app(BrandVoiceManager::class);

        $this->assertInstanceOf(BrandVoiceManager::class, $manager);
        // Registered as a singleton: same instance on subsequent resolutions.
        $this->assertSame($manager, app(BrandVoiceManager::class));
    }

    public function test_request_with_brand_voice_id_augments_the_prompt()
    {
        $manager = app(BrandVoiceManager::class);
        $voice = $manager->createBrandVoice('user-123', [
            'name' => 'Developer Brand',
            'tone' => 'professional',
            'target_audience' => 'developers',
        ]);

        $factory = app(GenerateApiRequestFactory::class);

        $request = $factory->text([
            'prompt' => 'Write a blog post about our new product.',
            'parameters' => ['brand_voice_id' => $voice['id']],
        ], 'user-123');

        $this->assertStringContainsString('Write a blog post about our new product.', $request->getPrompt());
        $this->assertStringContainsString('Brand voice instructions:', $request->getPrompt());
        $this->assertStringContainsString('professional', $request->getPrompt());
        $this->assertStringContainsString('developers', $request->getPrompt());
    }

    public function test_request_without_brand_voice_id_is_unchanged()
    {
        $factory = app(GenerateApiRequestFactory::class);
        $original = 'Write a blog post about our new product.';

        $request = $factory->text([
            'prompt' => $original,
        ], 'user-123');

        $this->assertSame($original, $request->getPrompt());
    }

    public function test_request_with_brand_voice_id_but_no_user_is_unchanged()
    {
        $manager = app(BrandVoiceManager::class);
        $voice = $manager->createBrandVoice('user-123', [
            'name' => 'Developer Brand',
            'tone' => 'professional',
        ]);

        $factory = app(GenerateApiRequestFactory::class);
        $original = 'Write a blog post about our new product.';

        $request = $factory->text([
            'prompt' => $original,
            'parameters' => ['brand_voice_id' => $voice['id']],
        ], null);

        $this->assertSame($original, $request->getPrompt());
    }
}
