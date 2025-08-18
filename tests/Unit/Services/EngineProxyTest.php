<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\EngineProxy;
use LaravelAIEngine\Services\UnifiedEngineManager;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class EngineProxyTest extends TestCase
{
    protected EngineProxy $proxy;
    protected UnifiedEngineManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->manager = Mockery::mock(UnifiedEngineManager::class);
        $this->proxy = new EngineProxy($this->manager);
    }

    public function test_engine_method_sets_engine()
    {
        $result = $this->proxy->engine('anthropic');

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_model_method_sets_model()
    {
        $result = $this->proxy->model('gpt-4o');

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_temperature_method_sets_temperature()
    {
        $result = $this->proxy->temperature(0.8);

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_max_tokens_method_sets_max_tokens()
    {
        $result = $this->proxy->maxTokens(1000);

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_user_method_sets_user()
    {
        $result = $this->proxy->user('user-123');

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_conversation_method_sets_conversation_id()
    {
        $result = $this->proxy->conversation('conv-123');

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_method_chaining()
    {
        $result = $this->proxy
            ->engine('openai')
            ->model('gpt-4o')
            ->temperature(0.7)
            ->maxTokens(500)
            ->user('user-456');

        $this->assertInstanceOf(EngineProxy::class, $result);
        $this->assertSame($this->proxy, $result);
    }

    public function test_send_with_configured_options()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->manager
            ->shouldReceive('send')
            ->with(
                $messages,
                [
                    'engine' => 'openai',
                    'model' => 'gpt-4o',
                    'temperature' => 0.7,
                    'max_tokens' => 500,
                    'user' => 'user-456',
                    'conversation_id' => 'conv-123'
                ]
            )
            ->once()
            ->andReturn($response);

        $result = $this->proxy
            ->engine('openai')
            ->model('gpt-4o')
            ->temperature(0.7)
            ->maxTokens(500)
            ->user('user-456')
            ->conversation('conv-123')
            ->send($messages);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_stream_with_configured_options()
    {
        $messages = [['role' => 'user', 'content' => 'Tell me a story']];
        
        $generator = (function() {
            yield 'chunk1';
            yield 'chunk2';
        })();

        $this->manager
            ->shouldReceive('stream')
            ->with(
                $messages,
                [
                    'engine' => 'anthropic',
                    'model' => 'claude-3-5-sonnet-20240620',
                    'temperature' => 0.8
                ]
            )
            ->once()
            ->andReturn($generator);

        $result = $this->proxy
            ->engine('anthropic')
            ->model('claude-3-5-sonnet-20240620')
            ->temperature(0.8)
            ->stream($messages);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    public function test_send_with_additional_options()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        $additionalOptions = ['top_p' => 0.9, 'frequency_penalty' => 0.1];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->manager
            ->shouldReceive('send')
            ->with(
                $messages,
                [
                    'engine' => 'openai',
                    'model' => 'gpt-4o',
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.1
                ]
            )
            ->once()
            ->andReturn($response);

        $result = $this->proxy
            ->engine('openai')
            ->model('gpt-4o')
            ->send($messages, $additionalOptions);

        $this->assertInstanceOf(AIResponse::class, $result);
        $this->assertTrue($result->success);
    }

    public function test_proxy_resets_after_send()
    {
        $messages = [['role' => 'user', 'content' => 'Hello']];
        
        $response = AIResponse::success(
            'Hello! How can I help you?',
            EngineEnum::OPENAI,
            EntityEnum::GPT_4O
        );

        $this->manager
            ->shouldReceive('send')
            ->twice()
            ->andReturn($response);

        // First call with configured options
        $this->proxy
            ->engine('openai')
            ->model('gpt-4o')
            ->temperature(0.7)
            ->send($messages);

        // Second call should not inherit previous configuration
        $this->manager
            ->shouldReceive('send')
            ->with($messages, [])
            ->once()
            ->andReturn($response);

        $this->proxy->send($messages);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
