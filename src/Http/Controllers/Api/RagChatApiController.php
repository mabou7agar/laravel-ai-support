<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Services\ActionService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use Illuminate\Support\Facades\Validator;

/**
 * RAG Chat API Controller
 *
 * RESTful API for AI chat with RAG (Retrieval-Augmented Generation)
 */
class RagChatApiController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected ConversationService $conversationService,
        protected ActionService $actionService,
        protected RAGCollectionDiscovery $ragDiscovery
    ) {}

    /**
     * Send a message to the AI chat
     *
     * @group Chat
     * @bodyParam message string required The message to send. Example: Tell me about Laravel routing
     * @bodyParam session_id string required Unique session identifier. Example: user-123
     * @bodyParam engine string Engine to use (openai, anthropic, gemini). Example: openai
     * @bodyParam model string Model to use. Example: gpt-4o
     * @bodyParam memory boolean Enable conversation memory. Example: true
     * @bodyParam actions boolean Enable suggested actions. Example: true
     * @bodyParam use_intelligent_rag boolean Enable intelligent RAG. Example: true
     * @bodyParam rag_collections array Array of model classes to search. Example: ["App\\Models\\Post"]
     * @bodyParam user_id string Optional user identifier. Example: user-456
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "response": "Laravel routing is...",
     *     "rag_enabled": true,
     *     "context_count": 5,
     *     "sources": [...],
     *     "actions": [...],
     *     "usage": {...},
     *     "session_id": "user-123"
     *   }
     * }
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
            'session_id' => 'required|string|max:255',
            'engine' => 'nullable|string|in:openai,anthropic,gemini',
            'model' => 'nullable|string',
            'memory' => 'nullable|boolean',
            'actions' => 'nullable|boolean',
            'use_intelligent_rag' => 'nullable|boolean',
            'rag_collections' => 'nullable|array',
            'user_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $message = $request->input('message');
            $sessionId = $request->input('session_id');
            $engine = $request->input('engine', 'openai');
            $model = $request->input('model', 'gpt-4o');
            $useMemory = $request->input('memory', true);
            $useActions = $request->input('actions', true);
            $useIntelligentRAG = $request->input('use_intelligent_rag', true);
            $userId = $request->input('user_id');

            // Get RAG collections
            $ragCollections = $request->input('rag_collections');
            if (empty($ragCollections)) {
                $ragCollections = $this->ragDiscovery->discover();
            }

            // Process message
            $response = $this->chatService->processMessage(
                message: $message,
                sessionId: $sessionId,
                engine: $engine,
                model: $model,
                useMemory: $useMemory,
                useActions: $useActions,
                useIntelligentRAG: $useIntelligentRAG,
                ragCollections: $ragCollections,
                userId: $userId
            );

            // Get metadata
            $metadata = $response->getMetadata();

            // Generate actions
            $actions = [];
            if ($useActions) {
                try {
                    $actions = $this->actionService->generateSuggestedActions(
                        $response->getContent(),
                        $sessionId,
                        $metadata
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to generate actions: ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'response' => $response->getContent(),
                    'rag_enabled' => $metadata['rag_enabled'] ?? false,
                    'context_count' => $metadata['context_count'] ?? 0,
                    'sources' => $metadata['sources'] ?? [],
                    'numbered_options' => $metadata['numbered_options'] ?? [],
                    'has_options' => $metadata['has_options'] ?? false,
                    'actions' => array_map(fn($action) => $action->toArray(), $actions),
                    'usage' => $response->getUsage() ?? [],
                    'session_id' => $sessionId,
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('RAG Chat API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Execute an action
     *
     * @group Actions
     * @bodyParam action_id string required Action identifier. Example: view_source_123
     * @bodyParam action_type string required Action type (button, quick_reply). Example: button
     * @bodyParam payload object required Action payload data. Example: {"action": "view_source", "model_id": 5}
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "action": "view_source",
     *     "url": "http://localhost/posts/5",
     *     "message": "Opening source..."
     *   }
     * }
     */
    public function executeAction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'action_id' => 'required|string',
            'action_type' => 'required|string|in:button,quick_reply',
            'payload' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->actionService->executeAction(
                $request->input('action_id'),
                $request->input('action_type'),
                $request->input('payload')
            );

            return response()->json([
                'success' => $result['success'] ?? false,
                'data' => $result
            ], $result['success'] ? 200 : 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get conversation history
     *
     * @group Chat
     * @urlParam session_id string required Session identifier. Example: user-123
     * @queryParam limit integer Maximum number of messages. Example: 50
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "messages": [...],
     *     "session_id": "user-123",
     *     "total": 10
     *   }
     * }
     */
    public function getHistory(string $sessionId, Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 50);
            // SECURITY: Only use authenticated user ID
            $userId = $request->user()?->id;

            $messages = $this->conversationService->getConversationHistory($sessionId, $limit, $userId);

            return response()->json([
                'success' => true,
                'data' => [
                    'messages' => collect($messages)->map(function ($message) {
                        return [
                            'role' => $message['role'] ?? 'unknown',
                            'content' => $message['content'] ?? '',
                            'timestamp' => $message['timestamp'] ?? null,
                        ];
                    })->values(),
                    'session_id' => $sessionId,
                    'total' => count($messages),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear conversation history
     *
     * @group Chat
     * @bodyParam session_id string required Session identifier. Example: user-123
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "message": "Chat history cleared successfully",
     *     "session_id": "user-123"
     *   }
     * }
     */
    public function clearHistory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'session_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $sessionId = $request->input('session_id');
            $cleared = $this->conversationService->clearConversation($sessionId);

            return response()->json([
                'success' => $cleared,
                'data' => [
                    'message' => $cleared ? 'Chat history cleared successfully' : 'No conversation found',
                    'session_id' => $sessionId,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available RAG collections
     *
     * @group RAG
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "collections": ["App\\Models\\Post", "App\\Models\\Article"],
     *     "count": 2
     *   }
     * }
     */
    public function getCollections(): JsonResponse
    {
        try {
            $collections = $this->ragDiscovery->discover();

            return response()->json([
                'success' => true,
                'data' => [
                    'collections' => $collections,
                    'count' => count($collections),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available engines and models
     *
     * @group Configuration
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "engines": {...},
     *     "default_engine": "openai",
     *     "default_model": "gpt-4o"
     *   }
     * }
     */
    public function getEngines(): JsonResponse
    {
        try {
            $engines = [
                'openai' => [
                    'name' => 'OpenAI',
                    'models' => ['gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
                'anthropic' => [
                    'name' => 'Anthropic',
                    'models' => ['claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
                'gemini' => [
                    'name' => 'Google Gemini',
                    'models' => ['gemini-1.5-pro', 'gemini-1.5-flash'],
                    'capabilities' => ['text', 'vision', 'function_calling'],
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'engines' => $engines,
                    'default_engine' => config('ai-engine.default', 'openai'),
                    'default_model' => 'gpt-4o',
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Health check
     *
     * @group System
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "status": "healthy",
     *     "version": "1.0.0",
     *     "rag_enabled": true,
     *     "collections_count": 2
     *   }
     * }
     */
    public function health(): JsonResponse
    {
        try {
            $collections = $this->ragDiscovery->discover();

            return response()->json([
                'success' => true,
                'data' => [
                    'status' => 'healthy',
                    'version' => '1.0.0',
                    'rag_enabled' => config('ai-engine.intelligent_rag.enabled', true),
                    'collections_count' => count($collections),
                    'timestamp' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user conversations
     *
     * @OA\Get(
     *   path="/api/v1/rag/conversations",
     *   summary="Get authenticated user's conversations",
     *   tags={"Chat"},
     *   security={{"bearerAuth": {}}},
     *   @OA\Parameter(
     *     name="limit",
     *     in="query",
     *     description="Number of conversations per page (default: 20)",
     *     required=false,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Parameter(
     *     name="page",
     *     in="query",
     *     description="Page number (default: 1)",
     *     required=false,
     *     @OA\Schema(type="integer")
     *   ),
     *   @OA\Response(
     *     response=200,
     *     description="Success"
     *   ),
     *   @OA\Response(
     *     response=401,
     *     description="Authentication required"
     *   )
     * )
     */
    public function getUserConversations(Request $request): JsonResponse
    {
        try {
            // SECURITY: Only use authenticated user ID, never accept user_id from request
            $userId = $request->user()?->id;

            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Authentication required',
                ], 401);
            }

            $limit = $request->input('limit', 20);
            $page = $request->input('page', 1);
            $offset = ($page - 1) * $limit;

            // Get conversations
            $conversations = \LaravelAIEngine\Models\Conversation::forUser($userId)
                ->active()
                ->orderBy('last_activity_at', 'desc')
                ->skip($offset)
                ->limit($limit)
                ->get();

            // Get total count for pagination
            $total = \LaravelAIEngine\Models\Conversation::forUser($userId)
                ->active()
                ->count();

            // Format conversations
            $formattedConversations = $conversations->map(function ($conversation) {
                // Get message count and last message
                $messageCount = $conversation->messages()->count();
                $lastMessage = $conversation->messages()
                    ->latest('sent_at')
                    ->first();

                return [
                    'conversation_id' => $conversation->conversation_id,
                    'title' => $conversation->title,
                    'message_count' => $messageCount,
                    'last_message' => $lastMessage ? [
                        'role' => $lastMessage->role,
                        'content' => substr($lastMessage->content, 0, 100) . (strlen($lastMessage->content) > 100 ? '...' : ''),
                        'sent_at' => $lastMessage->sent_at->toISOString(),
                    ] : null,
                    'last_activity_at' => $conversation->last_activity_at?->toISOString(),
                    'created_at' => $conversation->created_at->toISOString(),
                    'settings' => $conversation->settings,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'conversations' => $formattedConversations,
                    'pagination' => [
                        'total' => $total,
                        'per_page' => $limit,
                        'current_page' => $page,
                        'last_page' => ceil($total / $limit),
                        'from' => $offset + 1,
                        'to' => min($offset + $limit, $total),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
