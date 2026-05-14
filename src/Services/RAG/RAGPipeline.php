<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Contracts\RAGPipelineContract;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\Agent\AgentRunEventStreamService;

class RAGPipeline implements RAGPipelineContract
{
    public function __construct(
        private readonly RAGQueryAnalyzer $analyzer,
        private readonly RAGCollectionResolver $collections,
        private readonly RAGRetriever $retriever,
        private readonly RAGContextBuilder $contextBuilder,
        private readonly RAGPromptBuilder $promptBuilder,
        private readonly RAGResponseGenerator $responseGenerator
    ) {}

    public function answer(string $query, array $options = [], int|string|null $userId = null): AgentResponse
    {
        $this->emit(AgentRunEventStreamService::RAG_STARTED, $options, ['query' => $query]);
        $analysis = $this->analyzer->analyze($query, $options);
        $collections = $this->collections->resolve($options);
        $sources = $this->retriever->retrieve($analysis['queries'], $collections, $options, $userId);
        $this->emit(AgentRunEventStreamService::RAG_SOURCES_FOUND, $options, [
            'result_count' => count($sources),
            'source_types' => array_values(array_unique(array_map(static fn ($source): string => $source->type, $sources))),
        ]);
        $context = $this->contextBuilder->build($sources);
        $prompt = $this->promptBuilder->build($query, $context['context'], $options);
        $response = $this->responseGenerator->generate($prompt, $context, array_merge($options, [
            'user_id' => $userId,
        ]));
        $response->metadata = array_merge($response->metadata ?? [], [
            'rag_analysis' => $analysis,
            'rag_collections' => $collections,
            'rag_result_count' => count($sources),
            'rag_source_types' => array_values(array_unique(array_map(static fn ($source): string => $source->type, $sources))),
        ]);
        $this->emit(AgentRunEventStreamService::RAG_COMPLETED, $options, [
            'result_count' => count($sources),
            'success' => $response->success,
        ]);

        return $response;
    }

    public function process(
        string $message,
        string $sessionId,
        array $collections = [],
        array $conversationHistory = [],
        array $options = [],
        mixed $userId = null
    ): AIResponse {
        $response = $this->answer($message, array_merge($options, [
            'session_id' => $sessionId,
            'rag_collections' => $collections !== [] ? $collections : ($options['rag_collections'] ?? []),
            'conversation_history' => $conversationHistory,
        ]), is_int($userId) || is_string($userId) ? $userId : null);

        return $response->toAIResponse();
    }

    private function emit(string $event, array $options, array $payload = []): void
    {
        app(AgentRunEventStreamService::class)->emit(
            $event,
            $options['agent_run_id'] ?? null,
            $options['agent_run_step_id'] ?? null,
            $payload,
            ['trace_id' => $options['trace_id'] ?? null]
        );
    }
}
