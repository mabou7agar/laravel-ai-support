<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Fal\FalMediaWorkflowService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalMediaWorkflowServiceTest extends TestCase
{
    public function test_prepare_request_loads_saved_characters_and_uses_demo_user_id(): void
    {
        config()->set('ai-engine.demo_user_id', '55');

        $store = app(FalCharacterStore::class);
        $store->save([
            'name' => 'Mina',
            'frontal_image_url' => 'https://example.com/mina.png',
            'reference_image_urls' => ['https://example.com/mina-side.png'],
            'metadata' => ['source' => 'test'],
        ], 'mina');

        $service = new FalMediaWorkflowService(
            Mockery::mock(AIEngineService::class),
            $store
        );

        $request = $service->prepareRequest('Make Mina walk', [
            'use_demo_user_id' => true,
            'use_characters' => ['mina'],
            'multi_prompt' => [
                ['prompt' => '@Element1 walks to camera', 'duration' => 4],
            ],
        ]);

        $this->assertSame('55', $request->getUserId());
        $this->assertSame(EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, $request->getModel()->value);
        $this->assertCount(1, $request->getParameters()['character_sources']);
        $this->assertSame('@Element1 walks to camera', $request->getParameters()['multi_prompt'][0]['prompt']);
    }

    public function test_prepare_request_omits_user_id_when_demo_fallback_disabled(): void
    {
        config()->set('ai-engine.demo_user_id', '55');

        $service = new FalMediaWorkflowService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $request = $service->prepareRequest('Make Mina walk', [
            'start_image_url' => 'https://example.com/start.png',
        ]);

        $this->assertNull($request->getUserId());
    }

    public function test_prepare_request_uses_seedance_reference_for_animation_reference(): void
    {
        $service = new FalMediaWorkflowService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $request = $service->prepareRequest('Use the same dance motion', [
            'animation_reference_url' => 'https://example.com/dance-reference.mp4',
        ]);

        $this->assertSame(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO, $request->getModel()->value);
        $this->assertSame(['https://example.com/dance-reference.mp4'], $request->getParameters()['reference_video_urls']);
        $this->assertSame(['https://example.com/dance-reference.mp4'], $request->getParameters()['video_urls']);
    }

    public function test_prepare_request_maps_reference_audio_urls(): void
    {
        $service = new FalMediaWorkflowService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $request = $service->prepareRequest('Use this rhythm', [
            'reference_video_urls' => ['https://example.com/motion.mp4'],
            'reference_audio_urls' => ['https://example.com/beat.mp3'],
        ]);

        $this->assertSame(EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO, $request->getModel()->value);
        $this->assertSame(['https://example.com/beat.mp3'], $request->getParameters()['audio_urls']);
    }

    public function test_generate_calls_ai_engine_service_with_explicit_user_id(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->once()
            ->withArgs(function (AIRequest $request): bool {
                $this->assertSame('abc', $request->getUserId());
                $this->assertSame(EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO, $request->getModel()->value);
                return true;
            })
            ->andReturn(
                AIResponse::success(
                    '{"video":{"url":"https://example.com/out.mp4"}}',
                    'fal_ai',
                    EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO
                )
            );

        $service = new FalMediaWorkflowService($aiEngineService, app(FalCharacterStore::class));
        $result = $service->generate('Animate this image', [
            'start_image_url' => 'https://example.com/start.png',
        ], 'abc');

        $this->assertTrue($result['response']->isSuccess());
    }
}
