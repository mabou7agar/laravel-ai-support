<?php

namespace LaravelAIEngine\Tests\Unit\Services\Fal;

use LaravelAIEngine\Services\Fal\FalAsyncCharacterGenerationService;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FalAsyncCharacterGenerationServiceTest extends TestCase
{
    public function test_submit_delegates_to_async_reference_pack_service_with_character_entity_type(): void
    {
        $referenceAsync = Mockery::mock(FalAsyncReferencePackGenerationService::class);
        $referenceAsync->shouldReceive('submit')
            ->once()
            ->withArgs(function (string $prompt, array $options, ?string $userId): bool {
                $this->assertSame('Generate Mina', $prompt);
                $this->assertSame('character', $options['entity_type']);
                $this->assertSame('mina-preview', $options['from_reference_pack']);
                $this->assertSame('42', $userId);
                return true;
            })
            ->andReturn([
                'job_id' => 'job-1',
                'status' => ['status' => 'queued'],
            ]);

        $service = new FalAsyncCharacterGenerationService($referenceAsync);

        $submitted = $service->submit('Generate Mina', [
            'from_character' => 'mina-preview',
        ], '42');

        $this->assertSame('job-1', $submitted['job_id']);
    }
}
