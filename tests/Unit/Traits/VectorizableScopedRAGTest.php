<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Services\RAG\RAGChatService;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;
use Mockery;

class VectorizableScopedRAGTest extends UnitTestCase
{
    public function test_vector_chat_passes_user_id_to_rag_service(): void
    {
        $rag = Mockery::mock(RAGChatService::class);
        $rag->shouldReceive('processMessage')
            ->once()
            ->with(
                'Tell me about Apollo',
                '9',
                [ScopedVectorizableRAGModel::class],
                [],
                Mockery::on(fn (array $options): bool => ($options['intelligent'] ?? null) === false),
                '9'
            )
            ->andReturn(AIResponse::success('answer', metadata: [
                'sources' => [['id' => 1]],
                'context_count' => 1,
            ]));

        $this->app->instance(RAGChatService::class, $rag);

        $result = ScopedVectorizableRAGModel::vectorChat('Tell me about Apollo', '9');

        $this->assertSame('answer', $result['response']);
        $this->assertSame(1, $result['context_count']);
        $this->assertCount(1, $result['sources']);
    }

    public function test_intelligent_chat_passes_user_id_option_to_rag_service(): void
    {
        $rag = Mockery::mock(RAGChatService::class);
        $rag->shouldReceive('processMessage')
            ->once()
            ->with(
                'Tell me about Apollo',
                'session-1',
                [ScopedVectorizableRAGModel::class],
                [],
                Mockery::on(fn (array $options): bool => ($options['user_id'] ?? null) === '9'),
                '9'
            )
            ->andReturn(AIResponse::success('answer', metadata: [
                'rag_enabled' => true,
                'context_count' => 1,
            ]));

        $this->app->instance(RAGChatService::class, $rag);

        $response = ScopedVectorizableRAGModel::intelligentChat('Tell me about Apollo', 'session-1', [
            'collections' => [ScopedVectorizableRAGModel::class],
            'user_id' => '9',
        ]);

        $this->assertSame('answer', $response->getContent());
        $this->assertTrue($response->getMetadata()['rag_enabled']);
    }
}

class ScopedVectorizableRAGModel extends Model
{
    use Vectorizable;

    protected $table = 'scoped_vectorizable_rag_models';
}
