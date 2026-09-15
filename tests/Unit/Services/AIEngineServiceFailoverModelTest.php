<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use Illuminate\Support\Facades\Event;
use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Drivers\DriverRegistry;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

final class AIEngineServiceFailoverModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        config()->set('ai-engine.credits.enabled', false);
        config()->set('ai-engine.nodes.enabled', false);
        config()->set('ai-engine.engines.anthropic.api_key', 'test-key');
        config()->set('ai-engine.error_handling.fallback_engines.openai', ['anthropic']);
        config()->set('ai-engine.error_handling.fallback_engines.anthropic', []);
    }

    public function test_generate_failover_maps_model_and_preserves_every_request_field(): void
    {
        config()->set('ai-engine.error_handling.fallback_models.anthropic', 'claude-sonnet-5');
        $anthropic = new FailoverCapturingDriver(EngineEnum::Anthropic, succeed: true);
        $service = $this->service(new FailoverCapturingDriver(EngineEnum::OpenAI, succeed: false), $anthropic);

        $functions = [['name' => 'find_invoice', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $request = (new AIRequest(
            prompt: 'Find invoice 7',
            engine: EngineEnum::OpenAI,
            model: 'gpt-4o',
            conversationId: 'conv-1',
            context: ['tenant' => 'acme'],
            files: ['/tmp/invoice.pdf'],
            systemPrompt: 'Be precise.',
            messages: [['role' => 'user', 'content' => 'earlier']],
            maxTokens: 321,
            temperature: 0.2,
            seed: 7,
        ))->withFunctions($functions, 'auto');

        $response = $service->generate($request);

        self::assertTrue($response->isSuccessful());
        $sent = $anthropic->received[0];
        self::assertSame(EngineEnum::Anthropic, $sent->getEngine());
        self::assertSame('claude-sonnet-5', $sent->getModel()->value);
        self::assertSame([['role' => 'user', 'content' => 'earlier']], $sent->getMessages());
        self::assertSame($functions, $sent->getFunctions());
        self::assertSame('auto', $sent->getFunctionCall());
        self::assertSame(['/tmp/invoice.pdf'], $sent->getFiles());
        self::assertSame(['tenant' => 'acme'], $sent->getContext());
        self::assertSame('conv-1', $sent->getConversationId());
        self::assertSame('Be precise.', $sent->getSystemPrompt());
        self::assertSame(321, $sent->getMaxTokens());
        self::assertSame(7, $sent->getSeed());
        self::assertSame('openai', $sent->getMetadata()['failover_from']);
        self::assertSame('gpt-4o', $sent->getMetadata()['failover_from_model']);
    }

    public function test_failover_uses_engine_default_model_when_no_mapping_is_configured(): void
    {
        config()->set('ai-engine.error_handling.fallback_models', []);
        config()->set('ai-engine.engines.anthropic.default_model', 'claude-haiku-4-5-20251001');
        $anthropic = new FailoverCapturingDriver(EngineEnum::Anthropic, succeed: true);
        $service = $this->service(new FailoverCapturingDriver(EngineEnum::OpenAI, succeed: false), $anthropic);

        $service->generate(new AIRequest('hello', EngineEnum::OpenAI, 'gpt-4o'));

        self::assertSame('claude-haiku-4-5-20251001', $anthropic->received[0]->getModel()->value);
    }

    public function test_failover_mapping_can_be_keyed_by_source_model(): void
    {
        config()->set('ai-engine.error_handling.fallback_models.anthropic', [
            'gpt-4o-mini' => 'claude-haiku-4-5-20251001',
            'default' => 'claude-opus-5',
        ]);
        $anthropic = new FailoverCapturingDriver(EngineEnum::Anthropic, succeed: true);
        $service = $this->service(new FailoverCapturingDriver(EngineEnum::OpenAI, succeed: false), $anthropic);

        $service->generate(new AIRequest('hello', EngineEnum::OpenAI, 'gpt-4o-mini'));
        $service->generate(new AIRequest('hello', EngineEnum::OpenAI, 'gpt-4o'));

        self::assertSame('claude-haiku-4-5-20251001', $anthropic->received[0]->getModel()->value);
        self::assertSame('claude-opus-5', $anthropic->received[1]->getModel()->value);
    }

    public function test_stream_failover_maps_model_and_preserves_functions(): void
    {
        config()->set('ai-engine.error_handling.fallback_models.anthropic', 'claude-sonnet-5');
        $anthropic = new FailoverCapturingDriver(EngineEnum::Anthropic, succeed: true);
        $service = $this->service(new FailoverCapturingDriver(EngineEnum::OpenAI, succeed: false), $anthropic);

        $functions = [['name' => 'lookup', 'parameters' => ['type' => 'object', 'properties' => []]]];
        $request = (new AIRequest(
            prompt: 'stream me',
            engine: EngineEnum::OpenAI,
            model: 'gpt-4o',
            messages: [['role' => 'assistant', 'content' => 'prior']],
        ))->withFunctions($functions);

        $chunks = iterator_to_array($service->stream($request), false);

        self::assertSame(['ok'], $chunks);
        $sent = $anthropic->received[0];
        self::assertSame('claude-sonnet-5', $sent->getModel()->value);
        self::assertSame($functions, $sent->getFunctions());
        self::assertSame([['role' => 'assistant', 'content' => 'prior']], $sent->getMessages());
    }

    private function service(EngineDriverInterface $primary, EngineDriverInterface $fallback): AIEngineService
    {
        $registry = new DriverRegistry($this->app);
        $registry->register(EngineEnum::OpenAI, static fn () => $primary);
        $registry->register(EngineEnum::Anthropic, static fn () => $fallback);

        return new AIEngineService(
            Mockery::mock(CreditManager::class)->shouldIgnoreMissing(),
            app(ConversationManager::class),
            $registry,
        );
    }
}

final class FailoverCapturingDriver implements EngineDriverInterface
{
    /** @var array<int, AIRequest> */
    public array $received = [];

    public function __construct(private EngineEnum $engine, private bool $succeed)
    {
    }

    public function generate(AIRequest $request): AIResponse
    {
        $this->received[] = $request;

        return $this->succeed
            ? AIResponse::success('ok', $request->getEngine(), $request->getModel())
            : AIResponse::error('primary down', $request->getEngine(), $request->getModel());
    }

    public function stream(AIRequest $request): \Generator
    {
        $this->received[] = $request;
        if (!$this->succeed) {
            throw new \RuntimeException('primary stream down');
        }

        yield 'ok';
    }

    public function validateRequest(AIRequest $request): bool
    {
        return true;
    }

    public function getEngine(): EngineEnum
    {
        return $this->engine;
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
}
