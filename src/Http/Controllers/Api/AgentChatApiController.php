<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Http\Controllers\Concerns\ExtractsConversationContextPayload;
use LaravelAIEngine\Http\Requests\SendMessageRequest;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;

class AgentChatApiController extends Controller
{
    use ExtractsConversationContextPayload;

    public function __construct(
        protected ChatService $chatService,
        protected RAGCollectionDiscovery $ragDiscovery,
    ) {}

    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();
            $ragCollections = $this->resolveRagCollections($request->input('rag_collections'));

            $response = $this->chatService->processMessage(
                message: $dto->message,
                sessionId: $dto->sessionId,
                engine: $dto->engine,
                model: $dto->model,
                useMemory: $dto->memory,
                useActions: $dto->actions,
                useRag: $request->boolean('use_rag', true),
                ragCollections: $ragCollections,
                userId: $dto->userId,
                searchInstructions: $dto->searchInstructions,
                extraOptions: $dto->agentOptions()
            );

            $metadata = $response->getMetadata();
            $actions = $dto->actions ? $response->getActions() : [];

            return response()->json([
                'success' => true,
                'data' => [
                    'response' => $response->getContent(),
                    'rag_enabled' => $metadata['rag_enabled'] ?? false,
                    'context_count' => $metadata['context_count'] ?? 0,
                    'sources' => $metadata['sources'] ?? [],
                    'numbered_options' => $metadata['numbered_options'] ?? [],
                    'has_options' => $metadata['has_options'] ?? false,
                    'actions' => array_map(fn ($action) => is_array($action) ? $action : $action->toArray(), $actions),
                    'usage' => $response->getUsage() ?? [],
                    'session_id' => $dto->sessionId,
                    ...$this->extractConversationContextPayload($metadata),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Agent Chat API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
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
