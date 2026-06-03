<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentConversationService;
use LaravelAIEngine\Services\Agent\Tools\SearchKnowledgeTool;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class SearchKnowledgeToolTest extends UnitTestCase
{
    public function test_it_delegates_to_the_rag_pipeline_and_returns_grounded_text(): void
    {
        $context = new UnifiedActionContext('search-knowledge', 7);

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->with(
                'refund policy',
                $context,
                Mockery::on(fn (array $options): bool => ($options['force_rag'] ?? false) === true && ($options['limit'] ?? null) === 3),
                Mockery::type('callable')
            )
            ->andReturn(AgentResponse::conversational('Refunds are issued within 14 days.', $context, ['rag_last_metadata' => ['hits' => 2]]));

        $tool = new SearchKnowledgeTool($conversation);

        $result = $tool->execute(['query' => 'refund policy', 'limit' => 3], $context);

        $this->assertTrue($result->success);
        $this->assertSame('Refunds are issued within 14 days.', $result->message);
        $this->assertSame('refund policy', $result->data['query']);
        $this->assertSame(['rag_last_metadata' => ['hits' => 2]], $result->data['metadata']);
    }

    public function test_it_rejects_an_empty_query_without_touching_the_pipeline(): void
    {
        $context = new UnifiedActionContext('search-knowledge-empty', 7);

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldNotReceive('executeSearchRAG');

        $tool = new SearchKnowledgeTool($conversation);

        $result = $tool->execute(['query' => '   '], $context);

        $this->assertFalse($result->success);
    }

    public function test_it_fails_cleanly_when_the_pipeline_returns_no_text(): void
    {
        $context = new UnifiedActionContext('search-knowledge-blank', 7);

        $conversation = Mockery::mock(AgentConversationService::class);
        $conversation->shouldReceive('executeSearchRAG')
            ->once()
            ->andReturn(AgentResponse::conversational('   ', $context));

        $tool = new SearchKnowledgeTool($conversation);

        $result = $tool->execute(['query' => 'anything'], $context);

        $this->assertFalse($result->success);
    }
}
