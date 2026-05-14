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
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $request = $service->prepareRequest('Generate Mina', [
            'frame_count' => 16,
            'look_size' => 4,
            'fallback_user_id' => '7',
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

    public function test_prepare_workflow_uses_guided_mode_by_default_when_look_id_is_provided(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate Mina', [
            'entity_type' => 'character',
            'frame_count' => 6,
            'look_size' => 4,
            'look_id' => 'festival_blue',
            'look_payload' => [
                'label' => 'Festival Blue',
                'instruction' => 'Keep the blue styling direction consistent across every view.',
            ],
        ]);

        $this->assertCount(6, $workflow);
        $this->assertSame('guided', $workflow[0]['look_mode']);
        $this->assertSame('festival_blue', $workflow[0]['look_variant']);
        $this->assertSame('Festival Blue', $workflow[0]['look_label']);
        $this->assertSame('Keep the blue styling direction consistent across every view.', $workflow[0]['look_instruction']);
        $this->assertSame(1, $workflow[0]['look_index']);
        $this->assertSame('front', $workflow[0]['view']);
        $this->assertSame('three_quarter', $workflow[1]['view']);
        $this->assertSame('side', $workflow[2]['view']);
        $this->assertSame('full_body', $workflow[3]['view']);
        $this->assertSame('beauty_variant', $workflow[4]['look_variant']);
        $this->assertSame('front', $workflow[4]['view']);
        $this->assertSame('festival_blue', $workflow[5]['selected_look']['id']);
        $this->assertSame('guided', $workflow[5]['selected_look']['mode']);
    }

    public function test_prepare_workflow_collapses_to_selected_look_in_strict_mode(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate Mina', [
            'entity_type' => 'character',
            'frame_count' => 6,
            'look_size' => 4,
            'look_id' => 'festival_blue',
            'look_mode' => 'strict_stored',
            'look_payload' => [
                'label' => 'Festival Blue',
                'instruction' => 'Keep the blue styling direction consistent across every view.',
            ],
        ]);

        $this->assertCount(6, $workflow);
        $this->assertSame('strict_stored', $workflow[0]['look_mode']);
        $this->assertSame('festival_blue', $workflow[0]['look_variant']);
        $this->assertSame('festival_blue', $workflow[4]['look_variant']);
        $this->assertSame('view_5', $workflow[4]['view']);
        $this->assertSame(1, $workflow[4]['look_index']);
        $this->assertSame('strict_stored', $workflow[0]['selected_look']['mode']);
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

    public function test_generate_and_store_saves_stored_urls_and_preserves_provider_fallback_urls(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->twice()
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [[
                        'url' => 'https://app.test/storage/generated/front.png',
                        'source_url' => 'https://v3.fal.media/files/front.png',
                    ]],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [[
                        'url' => 'https://app.test/storage/generated/side.png',
                        'source_url' => 'https://v3.fal.media/files/side.png',
                    ]],
                ])
            );

        $store = app(FalCharacterStore::class);
        $service = new FalReferencePackGenerationService($aiEngineService, $store);

        $result = $service->generateAndStore('Generate Mina', [
            'name' => 'Mina',
            'save_as' => 'mina-fal-source',
            'frame_count' => 2,
        ], '42');

        $this->assertSame('https://app.test/storage/generated/front.png', $result['reference_pack']['frontal_image_url']);
        $this->assertSame(['https://app.test/storage/generated/side.png'], $result['reference_pack']['reference_image_urls']);
        $this->assertSame('https://v3.fal.media/files/front.png', $result['reference_pack']['frontal_provider_image_url']);
        $this->assertSame(['https://v3.fal.media/files/side.png'], $result['reference_pack']['provider_reference_image_urls']);
        $this->assertSame('https://app.test/storage/generated/front.png', $result['reference_pack']['metadata']['generated_images'][0]);
        $this->assertSame('https://v3.fal.media/files/side.png', $result['reference_pack']['metadata']['views'][1]['provider_url']);
    }

    public function test_generate_and_store_retries_edit_steps_with_provider_urls_after_stored_url_failure(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->times(3)
            ->withArgs(function (AIRequest $request): bool {
                static $calls = 0;
                $calls++;

                if ($calls === 1) {
                    $this->assertSame(EntityEnum::FAL_NANO_BANANA_2, $request->getModel()->value);
                    return true;
                }

                $this->assertSame(EntityEnum::FAL_NANO_BANANA_2_EDIT, $request->getModel()->value);
                $sourceImages = $request->getParameters()['source_images'] ?? [];

                if ($calls === 2) {
                    $this->assertSame(['https://app.test/storage/generated/front.png'], $sourceImages);
                }

                if ($calls === 3) {
                    $this->assertSame(['https://v3.fal.media/files/front.png'], $sourceImages);
                }

                return true;
            })
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [[
                        'url' => 'https://app.test/storage/generated/front.png',
                        'source_url' => 'https://v3.fal.media/files/front.png',
                    ]],
                ]),
                AIResponse::error('Stored URL could not be fetched by FAL', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [[
                        'url' => 'https://app.test/storage/generated/side.png',
                        'source_url' => 'https://v3.fal.media/files/side.png',
                    ]],
                ])
            );

        $service = new FalReferencePackGenerationService($aiEngineService, app(FalCharacterStore::class));

        $result = $service->generateAndStore('Generate Mina', [
            'name' => 'Mina',
            'save_as' => 'mina-fallback',
            'frame_count' => 2,
        ], '42');

        $this->assertSame('mina-fallback', $result['alias']);
        $this->assertSame('https://app.test/storage/generated/front.png', $result['reference_pack']['frontal_image_url']);
        $this->assertSame('https://v3.fal.media/files/front.png', $result['reference_pack']['frontal_provider_image_url']);
    }

    public function test_generate_and_store_persists_selected_look_metadata_in_reference_pack_and_response(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->twice()
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [[
                        'url' => 'https://example.com/mina-front.png',
                    ]],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [[
                        'url' => 'https://example.com/mina-side.png',
                    ]],
                ])
            );

        $service = new FalReferencePackGenerationService($aiEngineService, app(FalCharacterStore::class));

        $result = $service->generateAndStore('Generate Mina', [
            'name' => 'Mina',
            'save_as' => 'mina-selected-look',
            'frame_count' => 2,
            'look_id' => 'festival_blue',
            'look_mode' => 'strict_stored',
            'look_payload' => [
                'label' => 'Festival Blue',
                'instruction' => 'Keep the blue styling direction consistent across every view.',
            ],
        ], '42');

        $this->assertSame('festival_blue', $result['reference_pack']['selected_look']['id']);
        $this->assertSame('strict_stored', $result['reference_pack']['selected_look']['mode']);
        $this->assertSame('Festival Blue', $result['reference_pack']['metadata']['selected_look']['label']);
        $this->assertSame('Keep the blue styling direction consistent across every view.', $result['reference_pack']['metadata']['selected_look']['instruction']);
        $this->assertSame('strict_stored', $result['reference_pack']['metadata']['look_mode']);
        $this->assertSame(1, $result['reference_pack']['metadata']['look_size']);
        $this->assertSame(2, $result['reference_pack']['metadata']['frames_per_look']);
        $this->assertSame('festival_blue', $result['response']->getMetadata()['selected_look']['id']);
        $this->assertSame('strict_stored', $result['response']->getMetadata()['look_mode']);
        $this->assertSame(1, $result['response']->getMetadata()['look_size']);
        $this->assertSame(2, $result['response']->getMetadata()['frames_per_look']);
    }

    public function test_prepare_workflow_reuses_persisted_selected_look_when_expanding_saved_preview(): void
    {
        $store = app(FalCharacterStore::class);
        $store->save([
            'name' => 'Mina Preview',
            'frontal_image_url' => 'https://example.com/mina-preview.png',
            'reference_image_urls' => [],
            'metadata' => [
                'entity_type' => 'character',
                'selected_look' => [
                    'id' => 'festival_blue',
                    'variant' => 'festival_blue',
                    'label' => 'Festival Blue',
                    'instruction' => 'Keep the blue styling direction consistent across every view.',
                    'collapse_workflow' => true,
                    'payload' => [
                        'label' => 'Festival Blue',
                        'instruction' => 'Keep the blue styling direction consistent across every view.',
                    ],
                ],
                'looks' => [[
                    'look_index' => 1,
                    'variant' => 'festival_blue',
                    'label' => 'Festival Blue',
                    'instruction' => 'Keep the blue styling direction consistent across every view.',
                ]],
            ],
        ], 'mina-preview-selected-look');

        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            $store
        );

        $workflow = $service->prepareWorkflow('Expand Mina', [
            'entity_type' => 'character',
            'from_reference_pack' => 'mina-preview-selected-look',
            'frame_count' => 3,
        ]);

        $this->assertCount(2, $workflow);
        $this->assertSame('strict_stored', $workflow[0]['look_mode']);
        $this->assertSame('festival_blue', $workflow[0]['look_variant']);
        $this->assertSame('Festival Blue', $workflow[0]['look_label']);
        $this->assertSame('side', $workflow[1]['view']);
    }

    public function test_prepare_workflow_boolean_alias_enables_strict_stored_mode(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate Mina', [
            'entity_type' => 'character',
            'frame_count' => 3,
            'look_id' => 'festival_blue',
            'strict_stored_looks' => true,
        ]);

        $this->assertCount(3, $workflow);
        $this->assertSame('strict_stored', $workflow[0]['look_mode']);
        $this->assertSame('festival_blue', $workflow[2]['look_variant']);
        $this->assertSame('side', $workflow[2]['view']);
    }

    public function test_prepare_workflow_uses_strict_selected_set_for_multiple_selected_looks(): void
    {
        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            app(FalCharacterStore::class)
        );

        $workflow = $service->prepareWorkflow('Generate Mina set', [
            'entity_type' => 'character',
            'frame_count' => 8,
            'selected_looks' => [
                [
                    'id' => 'business-street-look',
                    'name' => 'Commercial Street Business Look',
                    'instruction' => 'Keep the commercial business wardrobe locked.',
                    'is_primary' => true,
                ],
                [
                    'id' => 'airport-disguise',
                    'name' => 'Airport Security Disguise',
                    'instruction' => 'Keep the airport disguise wardrobe locked.',
                ],
                [
                    'id' => 'night-club-exit',
                    'name' => 'Night Club Exit',
                    'instruction' => 'Keep the nightclub exit look locked.',
                ],
            ],
        ]);

        $this->assertCount(8, $workflow);
        $this->assertSame('strict_selected_set', $workflow[0]['look_mode']);
        $this->assertSame('business-street-look', $workflow[0]['look_variant']);
        $this->assertSame('front', $workflow[0]['view']);
        $this->assertSame('business-street-look', $workflow[2]['look_variant']);
        $this->assertSame('side', $workflow[2]['view']);
        $this->assertSame('airport-disguise', $workflow[3]['look_variant']);
        $this->assertSame('front', $workflow[3]['view']);
        $this->assertSame('airport-disguise', $workflow[5]['look_variant']);
        $this->assertSame('side', $workflow[5]['view']);
        $this->assertSame('night-club-exit', $workflow[6]['look_variant']);
        $this->assertSame('front', $workflow[6]['view']);
        $this->assertSame('night-club-exit', $workflow[7]['look_variant']);
        $this->assertSame('three_quarter', $workflow[7]['view']);
        $this->assertSame('business-street-look', $workflow[7]['selected_look']['id']);
        $this->assertCount(3, $workflow[7]['selected_looks']);
        $this->assertNotContains('beauty_variant', array_column($workflow, 'look_variant'));
        $this->assertNotContains('fashion_variant', array_column($workflow, 'look_variant'));
    }

    public function test_prepare_workflow_skips_only_first_view_of_first_selected_look_when_expanding_preview(): void
    {
        $store = app(FalCharacterStore::class);
        $store->save([
            'name' => 'Mina Strict Set Preview',
            'frontal_image_url' => 'https://example.com/mina-preview.png',
            'reference_image_urls' => [],
            'metadata' => [
                'entity_type' => 'character',
                'look_mode' => 'strict_selected_set',
                'selected_look' => [
                    'id' => 'business-street-look',
                    'variant' => 'business-street-look',
                    'label' => 'Commercial Street Business Look',
                    'instruction' => 'Keep the commercial business wardrobe locked.',
                    'mode' => 'strict_selected_set',
                    'payload' => [
                        'id' => 'business-street-look',
                        'name' => 'Commercial Street Business Look',
                        'instruction' => 'Keep the commercial business wardrobe locked.',
                        'is_primary' => true,
                    ],
                ],
                'selected_looks' => [
                    [
                        'id' => 'business-street-look',
                        'variant' => 'business-street-look',
                        'label' => 'Commercial Street Business Look',
                        'instruction' => 'Keep the commercial business wardrobe locked.',
                        'mode' => 'strict_selected_set',
                        'is_primary' => true,
                        'payload' => [
                            'id' => 'business-street-look',
                            'name' => 'Commercial Street Business Look',
                            'instruction' => 'Keep the commercial business wardrobe locked.',
                            'is_primary' => true,
                        ],
                    ],
                    [
                        'id' => 'airport-disguise',
                        'variant' => 'airport-disguise',
                        'label' => 'Airport Security Disguise',
                        'instruction' => 'Keep the airport disguise wardrobe locked.',
                        'mode' => 'strict_selected_set',
                        'payload' => [
                            'id' => 'airport-disguise',
                            'name' => 'Airport Security Disguise',
                            'instruction' => 'Keep the airport disguise wardrobe locked.',
                        ],
                    ],
                    [
                        'id' => 'night-club-exit',
                        'variant' => 'night-club-exit',
                        'label' => 'Night Club Exit',
                        'instruction' => 'Keep the nightclub exit look locked.',
                        'mode' => 'strict_selected_set',
                        'payload' => [
                            'id' => 'night-club-exit',
                            'name' => 'Night Club Exit',
                            'instruction' => 'Keep the nightclub exit look locked.',
                        ],
                    ],
                ],
                'looks' => [[
                    'look_index' => 1,
                    'variant' => 'business-street-look',
                    'label' => 'Commercial Street Business Look',
                    'instruction' => 'Keep the commercial business wardrobe locked.',
                ]],
            ],
        ], 'mina-strict-set-preview');

        $service = new FalReferencePackGenerationService(
            Mockery::mock(AIEngineService::class),
            $store
        );

        $workflow = $service->prepareWorkflow('Expand Mina strict set', [
            'entity_type' => 'character',
            'from_reference_pack' => 'mina-strict-set-preview',
            'frame_count' => 8,
        ]);

        $this->assertCount(7, $workflow);
        $this->assertSame('strict_selected_set', $workflow[0]['look_mode']);
        $this->assertSame('business-street-look', $workflow[0]['look_variant']);
        $this->assertSame('three_quarter', $workflow[0]['view']);
        $this->assertSame('business-street-look', $workflow[1]['look_variant']);
        $this->assertSame('side', $workflow[1]['view']);
        $this->assertSame('airport-disguise', $workflow[2]['look_variant']);
        $this->assertSame('front', $workflow[2]['view']);
    }

    public function test_generate_and_store_persists_selected_look_set_metadata_without_vendor_variants(): void
    {
        $aiEngineService = Mockery::mock(AIEngineService::class);
        $aiEngineService->shouldReceive('generateDirect')
            ->times(4)
            ->andReturn(
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2)->withMetadata([
                    'images' => [['url' => 'https://example.com/look1-front.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/look1-three-quarter.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/look2-front.png']],
                ]),
                AIResponse::success('{"images":[]}', 'fal_ai', EntityEnum::FAL_NANO_BANANA_2_EDIT)->withMetadata([
                    'images' => [['url' => 'https://example.com/look2-three-quarter.png']],
                ]),
            );

        $service = new FalReferencePackGenerationService($aiEngineService, app(FalCharacterStore::class));

        $result = $service->generateAndStore('Generate Mina strict set', [
            'entity_type' => 'character',
            'name' => 'Mina Strict Set',
            'save_as' => 'mina-strict-set',
            'frame_count' => 4,
            'selected_looks' => [
                [
                    'id' => 'business-street-look',
                    'name' => 'Commercial Street Business Look',
                    'instruction' => 'Keep the commercial business wardrobe locked.',
                    'is_primary' => true,
                ],
                [
                    'id' => 'airport-disguise',
                    'name' => 'Airport Security Disguise',
                    'instruction' => 'Keep the airport disguise wardrobe locked.',
                ],
            ],
        ], '42');

        $this->assertSame('strict_selected_set', $result['reference_pack']['metadata']['look_mode']);
        $this->assertSame(2, $result['reference_pack']['metadata']['look_count']);
        $this->assertSame(2, $result['reference_pack']['metadata']['frames_per_look']);
        $this->assertCount(2, $result['reference_pack']['metadata']['selected_looks']);
        $this->assertSame('business-street-look', $result['reference_pack']['metadata']['selected_looks'][0]['id']);
        $this->assertSame('airport-disguise', $result['reference_pack']['metadata']['selected_looks'][1]['id']);
        $this->assertSame(['business-street-look', 'airport-disguise'], array_column($result['reference_pack']['metadata']['looks'], 'variant'));
        $this->assertNotContains('beauty_variant', array_column($result['reference_pack']['metadata']['looks'], 'variant'));
        $this->assertSame('strict_selected_set', $result['response']->getMetadata()['look_mode']);
        $this->assertSame(2, $result['response']->getMetadata()['look_count']);
        $this->assertSame(2, $result['response']->getMetadata()['frames_per_look']);
        $this->assertCount(2, $result['response']->getMetadata()['selected_looks']);
    }
}
