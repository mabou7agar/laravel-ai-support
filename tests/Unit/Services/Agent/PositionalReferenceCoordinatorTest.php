<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use LaravelAIEngine\Services\Agent\IntentClassifierService;
use LaravelAIEngine\Services\Agent\PositionalReferenceCoordinator;
use Mockery;
use PHPUnit\Framework\TestCase;

class PositionalReferenceCoordinatorTest extends TestCase
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

    public function test_returns_error_when_position_cannot_be_resolved(): void
    {
        $context = new UnifiedActionContext('session-1', 1);
        $coordinator = $this->makeCoordinator();

        $response = $coordinator->handle(
            'unknown reference',
            $context,
            [],
            null,
            fn () => AgentResponse::success('unexpected', context: $context)
        );

        $this->assertFalse($response->needsUserInput);
        $this->assertStringContainsString("couldn't understand which item", strtolower($response->message));
    }

    public function test_enriches_context_and_delegates_to_ask_ai_callback(): void
    {
        $context = new UnifiedActionContext(
            'session-2',
            1,
            [
                [
                    'role' => 'assistant',
                    'content' => '1. Invoice 101',
                    'metadata' => [
                        'entity_ids' => [101],
                        'entity_type' => 'invoice',
                    ],
                ],
            ]
        );
        $coordinator = $this->makeCoordinator(['invoice' => PositionalReferenceTestModel::class]);
        $delegated = false;

        $response = $coordinator->handle(
            'first',
            $context,
            [],
            1,
            function (string $message, UnifiedActionContext $askContext) use (&$delegated): AgentResponse {
                $delegated = true;
                $this->assertSame('first', $message);
                $this->assertSame(101, $askContext->metadata['selected_entity_context']['entity_id']);
                $this->assertSame('positional_reference', $askContext->metadata['selected_entity_context']['selected_via']);
                return AgentResponse::conversational('delegated', context: $askContext);
            }
        );

        $this->assertTrue($delegated);
        $this->assertSame('delegated', $response->message);
    }

    public function test_returns_error_when_position_not_found_in_previous_list(): void
    {
        $context = new UnifiedActionContext(
            'session-3',
            1,
            [
                [
                    'role' => 'assistant',
                    'content' => '1. Invoice 1',
                    'metadata' => [
                        'entity_ids' => [1],
                        'entity_type' => 'invoice',
                    ],
                ],
            ]
        );
        $coordinator = $this->makeCoordinator(['invoice' => PositionalReferenceTestModel::class]);

        $response = $coordinator->handle(
            'second',
            $context,
            [],
            2,
            fn () => AgentResponse::success('unexpected', context: $context)
        );

        $this->assertStringContainsString("couldn't find item #2", strtolower($response->message));
    }

    protected function makeCoordinator(array $entityMap = []): PositionalReferenceCoordinator
    {
        return new PositionalReferenceCoordinator(
            new IntentClassifierService($this->intentConfig()),
            new FollowUpStateService($entityMap)
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

class PositionalReferenceTestModel
{
    public static function find(int $id): PositionalReferenceTestRecord
    {
        return new PositionalReferenceTestRecord($id);
    }
}

class PositionalReferenceTestRecord
{
    public function __construct(
        protected int $id
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'description' => 'Positional detail',
        ];
    }
}
