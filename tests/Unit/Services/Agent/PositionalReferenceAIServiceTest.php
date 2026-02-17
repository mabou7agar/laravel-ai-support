<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use LaravelAIEngine\Services\Agent\IntentClassifierService;
use LaravelAIEngine\Services\Agent\PositionalReferenceAIService;
use LaravelAIEngine\Services\AIEngineService;
use Mockery;
use PHPUnit\Framework\TestCase;

class PositionalReferenceAIServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Facade::clearResolvedInstances();

        $app = new Container();
        $logger = Mockery::mock();
        $logger->shouldReceive('channel')->andReturnSelf();
        $logger->shouldReceive('info')->andReturnNull();
        $logger->shouldReceive('debug')->andReturnNull();
        $logger->shouldReceive('warning')->andReturnNull();
        $logger->shouldReceive('error')->andReturnNull();

        $app->instance('log', $logger);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_null_without_entity_list_context(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );

        $position = $service->resolvePosition('the second one', new UnifiedActionContext('s-1', 1));

        $this->assertNull($position);
    }

    public function test_resolves_position_with_rule_fallback_when_disabled(): void
    {
        $service = $this->makeService(
            Mockery::mock(AIEngineService::class),
            ['enabled' => false]
        );

        $position = $service->resolvePosition('show me the second invoice', $this->makeContextWithEntityList());

        $this->assertSame(2, $position);
    }

    public function test_resolves_position_with_ai_when_enabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("POSITION: 3\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'max_position' => 100,
        ]);

        $position = $service->resolvePosition('what about number 3?', $this->makeContextWithEntityList());

        $this->assertSame(3, $position);
    }

    public function test_returns_null_when_ai_returns_position_outside_limits(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("POSITION: 400\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'max_position' => 100,
        ]);

        $position = $service->resolvePosition('show number 400', $this->makeContextWithEntityList());

        $this->assertNull($position);
    }

    public function test_resolves_position_with_custom_protocol_key_and_none_label(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("CHOICE: 2\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'response_key' => 'CHOICE',
            'none_value' => 'N/A',
            'max_position' => 100,
        ]);

        $position = $service->resolvePosition('the second one', $this->makeContextWithEntityList());
        $this->assertSame(2, $position);
    }

    public function test_returns_null_when_ai_returns_none_and_rules_fallback_disabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("POSITION: NONE\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'rules_fallback_on_ai_failure' => false,
        ]);

        $position = $service->resolvePosition('show me the second invoice', $this->makeContextWithEntityList());
        $this->assertNull($position);
    }

    public function test_uses_rule_fallback_when_ai_returns_none_and_rules_fallback_enabled(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn($this->makeAIResponse("POSITION: NONE\n"));

        $service = $this->makeService($ai, [
            'enabled' => true,
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'rules_fallback_on_ai_failure' => true,
        ]);

        $position = $service->resolvePosition('show me the second invoice', $this->makeContextWithEntityList());
        $this->assertSame(2, $position);
    }

    protected function makeService(AIEngineService $ai, array $settings = []): PositionalReferenceAIService
    {
        return new PositionalReferenceAIService(
            $ai,
            new IntentClassifierService($this->intentConfig()),
            new FollowUpStateService(),
            $settings
        );
    }

    protected function makeContextWithEntityList(): UnifiedActionContext
    {
        $context = new UnifiedActionContext('session-1', 1);
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [11, 12, 13],
            'entity_type' => 'invoice',
        ];

        return $context;
    }

    protected function makeAIResponse(string $content): AIResponse
    {
        return AIResponse::success(
            $content,
            EngineEnum::from('openai'),
            EntityEnum::from('gpt-4o-mini')
        );
    }

    protected function intentConfig(): array
    {
        return [
            'list_verbs' => ['list', 'show', 'display', 'search', 'find', 'fetch', 'retrieve', 'refresh', 'relist'],
            'refresh_words' => ['again', 'reload'],
            'record_terms' => ['invoices', 'emails', 'items', 'records'],
            'entity_terms' => ['invoice', 'email', 'item', 'record', 'entry', 'customer', 'product'],
            'followup_keywords' => [
                'what', 'which', 'who', 'when', 'where', 'why', 'how',
                'total', 'sum', 'count', 'average', 'status', 'due',
                'paid', 'unpaid', 'latest', 'earliest',
            ],
            'followup_pronouns' => ['it', 'its', 'them', 'those', 'these', 'that', 'this', 'ones'],
            'ordinal_words' => ['first', 'second', 'third', 'fourth', 'fifth', '1st', '2nd', '3rd', '4th', '5th'],
            'ordinal_map' => [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
                'fifth' => 5,
                '1st' => 1,
                '2nd' => 2,
                '3rd' => 3,
                '4th' => 4,
                '5th' => 5,
            ],
            'positional_entity_words' => ['item', 'email', 'invoice', 'entry', 'record'],
            'max_positional_index' => 100,
            'max_option_selection' => 10,
        ];
    }
}
