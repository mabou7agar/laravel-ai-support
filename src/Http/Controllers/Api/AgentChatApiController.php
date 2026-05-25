<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Http\Controllers\Concerns\ExtractsConversationContextPayload;
use LaravelAIEngine\Http\Requests\SendMessageRequest;
use LaravelAIEngine\Services\Agent\AgentChatExecutionModeResolver;
use LaravelAIEngine\Services\Agent\AgentChatRunService;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Support\JsonPayloadSanitizer;

class AgentChatApiController extends Controller
{
    use ExtractsConversationContextPayload;

    public function __construct(
        protected ChatService $chatService,
        protected RAGCollectionDiscovery $ragDiscovery,
        protected AgentChatRunService $agentChatRuns,
        protected AgentChatExecutionModeResolver $executionModes,
        protected JsonPayloadSanitizer $jsonPayloads,
    ) {}

    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();
            $ragCollections = $this->resolveRagCollections($request->input('rag_collections'));
            $useRag = $request->boolean('use_rag', true);
            $execution = $this->executionModes->resolve($dto, $useRag, $ragCollections);
            $executionOptions = array_merge($dto->agentOptions(), [
                'execution_mode_resolved' => $execution->mode,
                'execution_mode_reason' => $execution->reason,
            ]);

            if ($execution->shouldQueue()) {
                return response()->json($this->jsonSafe([
                    'success' => true,
                    'data' => array_merge($this->agentChatRuns->start([
                        'message' => $dto->message,
                        'session_id' => $dto->sessionId,
                        'user_id' => $dto->userId,
                        'options' => array_merge([
                            'engine' => $dto->engine,
                            'model' => $dto->model,
                            'memory' => $dto->memory,
                            'actions' => $dto->actions,
                            'streaming' => $dto->streaming,
                            'use_memory' => $dto->memory,
                            'use_actions' => $dto->actions,
                            'use_rag' => $useRag,
                            'rag_collections' => $ragCollections,
                            'search_instructions' => $dto->searchInstructions,
                        ], $executionOptions),
                    ]), $execution->toArray()),
                ]), 202);
            }

            $response = $this->chatService->processMessage(
                message: $dto->message,
                sessionId: $dto->sessionId,
                engine: $dto->engine,
                model: $dto->model,
                useMemory: $dto->memory,
                useActions: $dto->actions,
                useRag: $useRag,
                ragCollections: $ragCollections,
                userId: $dto->userId,
                searchInstructions: $dto->searchInstructions,
                extraOptions: $executionOptions
            );

            $metadata = $response->getMetadata();
            $actions = $dto->actions ? $response->getActions() : [];

            return response()->json($this->jsonSafe([
                'success' => true,
                'data' => [
                    'response' => $response->getContent(),
                    'metadata' => $metadata,
                    'rag_enabled' => $metadata['rag_enabled'] ?? false,
                    'context_count' => $metadata['context_count'] ?? 0,
                    'sources' => $metadata['sources'] ?? [],
                    'numbered_options' => $metadata['numbered_options'] ?? [],
                    'has_options' => $metadata['has_options'] ?? false,
                    'response_points' => $metadata['response_points'] ?? [],
                    'response_points_format' => $metadata['response_points_format'] ?? 'text',
                    'response_text_without_points' => $metadata['response_text_without_points'] ?? null,
                    'suggestions' => $metadata['suggestions'] ?? [],
                    'collection' => $metadata['collection'] ?? null,
                    'needs_user_input' => (bool) ($metadata['needs_user_input'] ?? false),
                    'required_inputs' => $metadata['required_inputs'] ?? [],
                    'runtime_data' => $metadata['runtime_data'] ?? [],
                    'actions' => array_map(fn ($action) => is_array($action) ? $action : $action->toArray(), $actions),
                    'usage' => $response->getUsage() ?? [],
                    'session_id' => $dto->sessionId,
                    ...$execution->toArray(),
                    ...$this->extractConversationContextPayload($metadata),
                ],
            ]));
        } catch (\Throwable $e) {
            Log::error('Agent Chat API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json($this->jsonSafe([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ]), 500);
        }
    }

    protected function jsonSafe(array $payload): array
    {
        $safe = $this->jsonPayloads->sanitize($payload);

        return is_array($safe) ? $safe : $payload;
    }

    protected function resolveRagCollections(mixed $ragCollections): array
    {
        if (is_array($ragCollections) && $ragCollections !== []) {
            return array_values(array_filter($ragCollections, 'is_string'));
        }

        if (
            config('ai-engine.nodes.enabled', false)
            && config('ai-engine.nodes.is_master', true)
            && config('ai-engine.nodes.search_mode', 'routing') === 'routing'
        ) {
            return [];
        }

        return $this->ragDiscovery->discover();
    }
}
