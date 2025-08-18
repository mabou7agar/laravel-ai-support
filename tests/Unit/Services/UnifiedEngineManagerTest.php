<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Memory\MemoryManager;
use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Services\MemoryProxy;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class UnifiedEngineManagerTest extends TestCase
{
    protected UnifiedEngineManager $manager;
    protected AIEngineService $aiEngineService;
    protected MemoryManager $memoryManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->aiEngineService = Mockery::mock(AIEngineService::class);
        $this->memoryManager = Mockery::mock(MemoryManager::class);
        
        $this->manager = new UnifiedEngineManager(
            $this->aiEngineService,
            $this->memoryManager
        );
    }

    public function test_engine_returns_engine_proxy()
    {
        $result = $this->manager->engine('openai');

        $this->assertInstanceOf(EngineProxy::class, $result);
    }

    public function test_memory_returns_memory_proxy()
    {
        $result = $this->manager->memory('redis');

        $this->assertInstanceOf(MemoryProxy::class, $result);
    }

    public function test_send_with_default_options()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->aiEngineService
            ->shouldReceive('generate')
            ->with(
                Mockery::on(function($request) use ($messages) {
                    return $request->messages === $messages &&
                           $request->engine === EngineEnum::OPENAI &&
                           $request->entity === EntityEnum::GPT_4O;
                })
            )
            ->once()
            ->andReturn($response);

        $result = $this->manager->send($messages);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_send_with_custom_options()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = [
            'engine' => 'anthropic',
            'model' => 'claude-3-5-sonnet-20240620',
            'temperature' => 0.8,
            'max_tokens' => 1000,
            'user' => 'user-123'
        ];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::ANTHROPIC,
            EntityEnum::CLAUDE_3_5_SONNET
        );

        $this->aiEngineService
            ->shouldReceive('generate')
            ->with(
                Mockery::on(function($request) use ($messages, $options) {
                    return $request->messages === $messages &&
                           $request->engine === EngineEnum::ANTHROPIC &&
                           $request->entity === EntityEnum::CLAUDE_3_5_SONNET &&
                           $request->temperature === 0.8 &&
                           $request->maxTokens === 1000 &&
                           $request->user === 'user-123';
                })
            )
            ->once()
            ->andReturn($response);

        $result = $this->manager->send($messages, $options);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_stream_with_default_options()
    {
        $messages = [['role' => 'user', 'content' => 'Tell me a story']];
        
        $generator = (function() {
            yield 'chunk1';
            yield 'chunk2';
        })();

        $this->aiEngineService
            ->shouldReceive('stream')
            ->with(
                Mockery::on(function($request) use ($messages) {
                    return $request->messages === $messages &&
                           $request->engine === EngineEnum::OPENAI &&
                           $request->entity === EntityEnum::GPT_4O;
                })
            )
            ->once()
            ->andReturn($generator);

        $result = $this->manager->stream($messages);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function test_get_engines()
    {
        $this->aiEngineService
            ->shouldReceive('getAvailableEngines')
            ->once()
            ->andReturn(['openai', 'anthropic', 'gemini']);

        $result = $this->manager->getEngines();

        $this->assertEquals(['openai', 'anthropic', 'gemini'], $result);
    }

    public function test_get_models()
    {
        $this->aiEngineService
            ->shouldReceive('getAvailableModels')
            ->with('openai')
            ->once()
            ->andReturn(['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo']);

        $result = $this->manager->getModels('openai');

        $this->assertEquals(['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'], $result);
    }

    public function test_send_with_conversation_context()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $options = ['conversation_id' => 'conv-123'];
        
        $contextMessages = [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'Previous message'],
            ['role' => 'assistant', 'content' => 'Previous response']
        ];

        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->memoryManager
            ->shouldReceive('getContext')
            ->with('conv-123', 50)
            ->once()
            ->andReturn($contextMessages);

        $this->aiEngineService
            ->shouldReceive('generate')
            ->with(
                Mockery::on(function($request) use ($contextMessages, $messages) {
                    $expectedMessages = array_merge($contextMessages, $messages);
                    return $request->messages === $expectedMessages;
                })
            )
            ->once()
            ->andReturn($response);

        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'user', 'Hello', [])
            ->once();

        $this->memoryManager
            ->shouldReceive('addMessage')
            ->with('conv-123', 'assistant', 'Hello! How can I help you?', [])
            ->once();

        $result = $this->manager->send($messages, $options);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
