<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Traits;

use Illuminate\Database\Eloquent\Model;
use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Tests\UnitTestCase;
use LaravelAIEngine\Traits\Vectorizable;
use Mockery;

class ScopedVectorizableRAGModel extends Model
{
    use Vectorizable;

    protected $table = 'scoped_vectorizable_rag_models';
}

class VectorizableScopedRAGTest extends UnitTestCase
{
    public function test_passes_user_id_as_conversation_scope_to_the_rag_pipeline_on_vector_chat(): void
    {
        $rag = Mockery::mock(RAGPipelineContract::class);
        $rag->shouldReceive('process')
            ->once()
            ->with(
                'Tell me about Apollo',
                '9',
                [ScopedVectorizableRAGModel::class],
                [],
                Mockery::on(static fn (array $options): bool => ($options['intelligent'] ?? null) === false),
                '9'
            )
            ->andReturn(AIResponse::success('answer', metadata: [
                'sources' => [['id' => 1]],
                'context_count' => 1,
            ]));

        app()->instance(RAGPipelineContract::class, $rag);

        $result = ScopedVectorizableRAGModel::vectorChat('Tell me about Apollo', '9');

        $this->assertSame('answer', $result['response']);
        $this->assertSame(1, $result['context_count']);
        $this->assertCount(1, $result['sources']);
    }

    public function test_forwards_user_id_option_to_the_rag_pipeline_on_intelligent_chat(): void
    {
        $rag = Mockery::mock(RAGPipelineContract::class);
        $rag->shouldReceive('process')
            ->once()
            ->with(
                'Tell me about Apollo',
                'session-1',
                [ScopedVectorizableRAGModel::class],
                [],
                Mockery::on(static fn (array $options): bool => ($options['user_id'] ?? null) === '9'),
                '9'
            )
            ->andReturn(AIResponse::success('answer', metadata: [
                'rag_enabled' => true,
                'context_count' => 1,
            ]));

        app()->instance(RAGPipelineContract::class, $rag);

        $response = ScopedVectorizableRAGModel::intelligentChat('Tell me about Apollo', 'session-1', [
            'collections' => [ScopedVectorizableRAGModel::class],
            'user_id' => '9',
        ]);

        $this->assertSame('answer', $response->getContent());
        $this->assertTrue($response->getMetadata()['rag_enabled']);
    }
}
