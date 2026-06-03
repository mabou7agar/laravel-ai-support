<?php

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Services\Media\ImageOperationService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ImageOperationServiceTest extends TestCase
{
    /** A capable image-edit driver that records the request it received. */
    private function editingDriver(): EngineDriverInterface
    {
        return new class implements EngineDriverInterface {
            public array $params = [];

            public function editImage(AIRequest $request): AIResponse
            {
                $this->params = $request->getParameters();

                return AIResponse::success('', $request->getEngine(), $request->getModel())
                    ->withMetadata(['operation' => $request->getParameters()['operation']]);
            }

            public function generate(AIRequest $request): AIResponse
            {
                return $this->editImage($request);
            }

            public function stream(AIRequest $request): \Generator
            {
                yield '';
            }

            public function validateRequest(AIRequest $request): bool
            {
                return true;
            }

            public function getEngine(): EngineEnum
            {
                return EngineEnum::Clipdrop;
            }

            public function supports(string $capability): bool
            {
                return true;
            }

            public function getAvailableModels(): array
            {
                return [];
            }

            public function test(): bool
            {
                return true;
            }

            public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
            {
                return '{}';
            }
        };
    }

    /** A driver with no editImage() method. */
    private function nonEditingDriver(): EngineDriverInterface
    {
        return new class implements EngineDriverInterface {
            public function generate(AIRequest $request): AIResponse
            {
                return AIResponse::success('', $request->getEngine(), $request->getModel());
            }

            public function stream(AIRequest $request): \Generator
            {
                yield '';
            }

            public function validateRequest(AIRequest $request): bool
            {
                return true;
            }

            public function getEngine(): EngineEnum
            {
                return EngineEnum::OpenAI;
            }

            public function supports(string $capability): bool
            {
                return false;
            }

            public function getAvailableModels(): array
            {
                return [];
            }

            public function test(): bool
            {
                return true;
            }

            public function generateJsonAnalysis(string $prompt, string $systemPrompt, ?string $model = null, int $maxTokens = 300): string
            {
                return '{}';
            }
        };
    }

    public function test_dispatches_operation_to_resolved_driver(): void
    {
        $driver = $this->editingDriver();
        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->with('clipdrop')->once()->andReturn($driver);

        $service = new ImageOperationService($registry);
        $response = $service->apply('background_removal', ['image' => 'raw']);

        $this->assertSame('background_removal', $response->getMetadata()['operation']);
        $this->assertSame('raw', $driver->params['image']);
    }

    public function test_aliases_are_normalized_for_the_driver(): void
    {
        $driver = $this->editingDriver();
        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->with('clipdrop')->andReturn($driver);

        $service = new ImageOperationService($registry);
        $response = $service->apply('object_removal', ['image' => 'raw', 'mask' => 'm']);

        $this->assertSame('cleanup', $response->getMetadata()['operation']);
        $this->assertSame('object_removal', $driver->params['requested_operation']);
    }

    public function test_unknown_operation_throws(): void
    {
        $registry = Mockery::mock(DriverRegistry::class);
        $service = new ImageOperationService($registry);

        $this->expectException(\InvalidArgumentException::class);
        $service->apply('frobnicate', ['image' => 'raw']);
    }

    public function test_engine_without_edit_support_throws(): void
    {
        $registry = Mockery::mock(DriverRegistry::class);
        $registry->shouldReceive('resolve')->andReturn($this->nonEditingDriver());

        $service = new ImageOperationService($registry);

        $this->expectException(\RuntimeException::class);
        $service->apply('upscale', ['image' => 'raw'], 'openai');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
