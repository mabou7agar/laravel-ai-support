<?php

namespace LaravelAIEngine\Tests\Unit\Facades;

use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Services\MemoryProxy;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class EngineTest extends TestCase
{
    protected UnifiedEngineManager $unifiedEngineManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->unifiedEngineManager = Mockery::mock(UnifiedEngineManager::class);
        $this->app->instance('unified-engine', $this->unifiedEngineManager);
    }

    public function test_engine_facade_returns_engine_proxy()
    {
        $engineProxy = Mockery::mock(EngineProxy::class);
        
        $this->unifiedEngineManager
            ->shouldReceive('engine')
            ->with('openai')
            ->once()
            ->andReturn($engineProxy);

        $result = Engine::engine('openai');

        $this->assertInstanceOf(EngineProxy::class, $result);
    }

    public function test_memory_facade_returns_memory_proxy()
    {
        $memoryProxy = Mockery::mock(MemoryProxy::class);
        
        $this->unifiedEngineManager
            ->shouldReceive('memory')
            ->with('redis')
            ->once()
            ->andReturn($memoryProxy);

        $result = Engine::memory('redis');

        $this->assertInstanceOf(MemoryProxy::class, $result);
    }

    public function test_send_facade_method()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['engine' => 'openai', 'model' => 'gpt-4o'];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->unifiedEngineManager
            ->shouldReceive('send')
            ->with($messages, $options)
            ->once()
            ->andReturn($response);

        $result = Engine::send($messages, $options);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
        $this->assertEquals('Hello! How can I help you?', $result->content);
    }

    public function test_stream_facade_method()
    {
        $messages = [['role' => 'user', 'content' => 'Tell me a story']];
        $options = ['engine' => 'openai'];
        
        $generator = (function() {
            yield 'chunk1';
            yield 'chunk2';
        })();

        $this->unifiedEngineManager
            ->shouldReceive('stream')
            ->with($messages, $options)
            ->once()
            ->andReturn($generator);

        $result = Engine::stream($messages, $options);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function test_get_engines_facade_method()
    {
        $engines = ['openai', 'anthropic', 'gemini'];

        $this->unifiedEngineManager
            ->shouldReceive('getEngines')
            ->once()
            ->andReturn($engines);

        $result = Engine::getEngines();

        $this->assertEquals($engines, $result);
    }

    public function test_get_models_facade_method()
    {
        $models = ['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'];

        $this->unifiedEngineManager
            ->shouldReceive('getModels')
            ->with('openai')
            ->once()
            ->andReturn($models);

        $result = Engine::getModels('openai');

        $this->assertEquals($models, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
