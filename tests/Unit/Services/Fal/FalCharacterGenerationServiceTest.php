<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\Fal\FalCharacterGenerationService;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalCharacterGenerationServiceTest extends TestCase
{
    public function test_prepare_request_delegates_to_reference_pack_service_with_character_entity_type(): void
    {
        $request = new AIRequest('Generate Mina', 'fal_ai', 'fal-ai/nano-banana-2');

        $referenceService = Mockery::mock(FalReferencePackGenerationService::class);
        $referenceService->shouldReceive('prepareRequest')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertSame('mina-preview', $options['from_reference_pack']);
                $this->assertSame('42', $userId);
                return true;
            })
            ->andReturn($request);

        $service = new FalCharacterGenerationService($referenceService);

        $result = $service->prepareRequest('Generate Mina', [
            'from_character' => 'mina-preview',
        ], '42');

        $this->assertSame($request, $result);
    }

    public function test_generate_and_store_delegates_to_reference_pack_service(): void
    {
        $referenceService = Mockery::mock(FalReferencePackGenerationService::class);
        $referenceService->shouldReceive('generateAndStore')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId, ?callable $progress): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertSame('42', $userId);
                $this->assertIsCallable($progress);
                return true;
            })
            ->andReturn([
                'alias' => 'mina',
                'character' => ['name' => 'Mina'],
            ]);

        $service = new FalCharacterGenerationService($referenceService);

        $result = $service->generateAndStore('Generate Mina', [], '42', static function (): void {
        });

        $this->assertSame('mina', $result['alias']);
    }
}
