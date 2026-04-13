<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalReferencePackGenerationServiceTest extends TestCase
{
    public function test_prepare_request_uses_character_profile_for_default_entity_type(): void
    {
        config()->set('ai-engine.demo_user_id', '7');

        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $request = $service->prepareRequest('Generate Mina', [
            'frame_count' => 16,
            'look_size' => 4,
            'use_demo_user_id' => true,
        ]);

        $this->assertSame('fal_ai', $request->getEngine()->value);
        $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $request->getModel()->value);
        $this->assertSame('7', $request->getUserId());
        $this->assertStringContainsString('character reference pack', strtolower($request->getPrompt()));
    }

    public function test_prepare_workflow_uses_entity_specific_profiles(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate couch', [
            'entity_type' => 'furniture',
            'frame_count' => 6,
            'look_size' => 4,
        ]);

        $this->assertCount(6, $workflow);
        $this->assertSame('furniture', $workflow[0]['entity_type']);
        $this->assertSame('material_variant', $workflow[4]['look_variant']);
        $this->assertStringContainsString('furniture', strtolower($workflow[0]['prompt']));
    }

    public function test_prepare_workflow_returns_single_step_for_preview_only(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate couch', [
            'entity_type' => 'furniture',
            'frame_count' => 16,
            'look_size' => 4,
            'preview_only' => true,
        ]);

        $this->assertCount(1, $workflow);
        $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $workflow[0]['model']);
        $this->assertSame('front', $workflow[0]['view']);
    }

    public function test_generate_and_store_persists_grouped_reference_pack_from_response(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->times(5)
            ->withArgs(function (AIRequest $request): bool {
                static $calls = 0;
                $calls++;

                if ($calls === 1) {
                    $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $request->getModel()->value);
                }

                if ($calls > 1) {
                    $this->assertSame(EntityEnum::FAL_NANO_BANANA_2_EDIT, $request->getModel()->value);
                    $this->assertSame('edit', $request->getParameters()['mode']);
                }

                return true;
            })
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [['url' => 'https://example.com/couch-look1-front.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/couch-look1-34.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/couch-look1-side.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/couch-look1-full.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/couch-look2-front.png']],
                ]),
            );

        $store = app(FalCharacterStore::class);
        $service = new FalReferencePackGenerationService($aiEngineService, $store);

        $result = $service->generateAndStore('Generate couch', [
            'entity_type' => 'furniture',
            'name' => 'Modern Couch',
            'save_as' => 'modern-couch',
            'frame_count' => 5,
            'look_size' => 4,
        ], '42');

        $this->assertSame('modern-couch', $result['alias']);
        $this->assertSame('https://example.com/couch-look1-front.png', $result['reference_pack']['frontal_image_url']);
        $this->assertCount(4, $result['reference_pack']['reference_image_urls']);
        $this->assertSame('reference_pack', $result['response']->getMetadata()['workflow']);
        $this->assertSame('furniture', $result['response']->getMetadata()['entity_type']);
    }

    public function test_generate_and_store_persists_voice_metadata_for_reuse_in_tts(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->once()
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [['url' => 'https://example.com/mina-front.png']],
                ])
            );

        $store = app(FalCharacterStore::class);
        $service = new FalReferencePackGenerationService($aiEngineService, $store);

        $result = $service->generateAndStore('Generate Mina', [
            'name' => 'Mina',
            'save_as' => 'mina',
            'frame_count' => 1,
            'voice_id' => 'voice-mina',
            'voice_settings' => [
                'stability' => 0.25,
                'similarity_boost' => 0.9,
                'style' => 0.1,
                'use_speaker_boost' => true,
            ],
        ], '42');

        $this->assertSame('voice-mina', $result['reference_pack']['voice_id']);
        $this->assertSame(0.25, $result['reference_pack']['voice_settings']['stability']);
        $this->assertSame('voice-mina', $store->voiceProfile('mina')['voice_id']);
        $this->assertSame(0.9, $store->voiceProfile('mina')['similarity_boost']);
    }
}
