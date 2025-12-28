<?php

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Services\ActionService;
use LaravelAIEngine\Events\AIActionTriggered;
use LaravelAIEngine\Http\Requests\SendMessageRequest;
use LaravelAIEngine\Http\Requests\ExecuteActionRequest;
use LaravelAIEngine\Http\Requests\ClearHistoryRequest;
use LaravelAIEngine\Http\Requests\UploadFileRequest;
use LaravelAIEngine\Http\Requests\ExecuteDynamicActionRequest;
use LaravelAIEngine\Services\DynamicActionService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\MemoryOptimizationService;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Services\Vector\VectorAuthorizationService;

class AIChatController extends Controller
{
    public function __construct(
        protected ChatService $chatService,
        protected ConversationService $conversationService,
        protected ActionService $actionService,
        protected DynamicActionService $dynamicActionService,
        protected RAGCollectionDiscovery $ragDiscovery,
        protected VectorAuthorizationService $authService,
        protected MemoryOptimizationService $memoryOptimization,
        protected ?IntelligentRAGService $intelligentRAG = null
    ) {
        // Apply auth middleware only if Sanctum or JWT is available
        $this->applyAuthMiddleware();
    }

    /**
     * Apply authentication middleware if available
     */
    protected function applyAuthMiddleware(): void
    {
        $guards = config('auth.guards', []);

        // Check if Sanctum is available
        if (isset($guards['sanctum'])) {
            $this->middleware('auth:sanctum')->except(['index', 'rag', 'getEngines']);
            return;
        }

        // Check if JWT is available
        if (isset($guards['jwt']) || isset($guards['api'])) {
            $guard = isset($guards['jwt']) ? 'jwt' : 'api';
            $this->middleware("auth:{$guard}")->except(['index', 'rag', 'getEngines']);
            return;
        }

        // No authentication guard available - skip middleware
    }
    /**
     * Display the enhanced chat demo
     */
    public function index()
    {
        return view('ai-engine::demo.chat-enhanced', [
            'title' => 'AI Chat Assistant - Enhanced',
        ]);
    }

    /**
     * Display the RAG chat demo
     */
    public function rag()
    {
        return view('ai-engine::demo.rag-chat-demo', [
            'title' => 'RAG Chat Demo',
        ]);
    }

    /**
     * Send a message to the AI and get a response
     */
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();

            $engine = $dto->engine;
            $model = $dto->model;
            $useMemory = $dto->memory;
            $useActions = $dto->actions;
            $useStreaming = $dto->streaming;

            // Check if API key is configured
            $apiKey = config("ai-engine.engines.{$engine}.api_key");
            if (empty($apiKey)) {
                return response()->json([
                    'success' => false,
                    'error' => "API key for {$engine} is not configured. Please set " . strtoupper($engine) . "_API_KEY in your .env file.",
                    'demo_mode' => true,
                    'response' => "👋 Hello! I'm a demo AI assistant. To enable real AI responses, please configure your API keys in the .env file:\n\n" .
                                "OPENAI_API_KEY=your-key-here\n" .
                                "ANTHROPIC_API_KEY=your-key-here\n" .
                                "GOOGLE_API_KEY=your-key-here\n\n" .
                                "For now, I'm running in demo mode. Your message was: \"{$dto->message}\"",
                ], 200); // Return 200 so the UI can display the demo message
            }

            // Get RAG collections from config or request
            $ragCollections = $request->input('rag_collections');
            
            Log::info('AIChatController: rag_collections input', [
                'raw_input' => $request->input('rag_collections'),
                'is_empty' => empty($ragCollections),
                'type' => gettype($ragCollections),
            ]);

            // If not provided, auto-discover from RAGgable/Vectorizable models
            if (empty($ragCollections)) {
                $ragCollections = $this->ragDiscovery->discover();
                Log::info('AIChatController: Auto-discovered collections', [
                    'count' => count($ragCollections),
                ]);
            } else {
                Log::info('AIChatController: Using user-passed collections', [
                    'count' => count($ragCollections),
                    'collections' => $ragCollections,
                ]);
            }

            $useIntelligentRAG = $request->input('intelligent_rag',
                $request->input('use_intelligent_rag',
                    config('ai-engine.intelligent_rag.enabled', true)
                )
            );

            // Process message using ChatService
            $response = $this->chatService->processMessage(
                message: $dto->message,
                sessionId: $dto->sessionId,
                engine: $engine,
                model: $model,
                useMemory: $useMemory,
                useActions: $useActions,
                useIntelligentRAG: $useIntelligentRAG,
                ragCollections: $ragCollections,
                userId: $dto->userId
            );

            // Get RAG metadata
            $metadata = $response->getMetadata();

            // Add interactive actions if enabled
            $actions = [];
            if ($useActions) {
                try {
                    $actions = $this->actionService->generateSuggestedActions(
                        $response->getContent(),
                        $dto->sessionId,
                        $metadata  // Pass RAG metadata to generate context-aware actions
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to generate actions: ' . $e->getMessage());
                }
            }

            // Track analytics (wrapped in try-catch)
            try {
                Engine::trackRequest([
                    'session_id' => $dto->sessionId,
                    'engine' => $engine,
                    'model' => $model,
                    'tokens' => $response->getUsage()['total_tokens'] ?? 0,
                    'cost' => 0,
                    'duration' => 0,
                    'user_id' => $dto->userId,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Failed to track analytics: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'response' => $response->getContent(),
                'actions' => array_map(fn($action) => is_array($action) ? $action : $action->toArray(), $actions),
                'usage' => $response->getUsage() ?? [],
                'session_id' => $dto->sessionId,
                // RAG metadata
                'rag_enabled' => $metadata['rag_enabled'] ?? false,
                'context_count' => $metadata['context_count'] ?? 0,
                'sources' => $metadata['sources'] ?? [],
                'numbered_options' => $metadata['numbered_options'] ?? [],
                'has_options' => $metadata['has_options'] ?? false,
                'aggregate_data' => $metadata['aggregate_data'] ?? [],
                // Smart actions metadata (inline action system)
                'smart_actions' => $metadata['smart_actions'] ?? [],
                'action_executed' => $metadata['action_executed'] ?? null,
            ]);

        } catch (\Exception $e) {
            \Log::error('Send message error: ' . $e->getMessage(), [
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
     * Execute an interactive action
     */
    public function executeAction(ExecuteActionRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();

            // Create action object
            $action = new InteractiveAction(
                id: $dto->actionId,
                type: ActionTypeEnum::from($dto->actionType),
                label: $dto->payload['label'] ?? 'Action',
                data: $dto->payload
            );

            // Execute action
            $actionResponse = Engine::executeAction($action, $dto->payload);

            // Fire action triggered event
            event(new AIActionTriggered(
                actionId: $dto->actionId,
                actionType: $dto->actionType,
                userId: $dto->userId,
                payload: $dto->payload,
                metadata: ['session_id' => $dto->sessionId]
            ));

            // Track action analytics
            Engine::trackAction([
                'session_id' => $dto->sessionId,
                'action_id' => $dto->actionId,
                'action_type' => $dto->actionType,
                'user_id' => $dto->userId,
            ]);

            return response()->json([
                'success' => $actionResponse->success,
                'data' => $actionResponse->data,
                'message' => $actionResponse->message,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get chat history for a session
     */
    public function getHistory(string $sessionId, Request $request): JsonResponse
    {
        try {
            // SECURITY: Only use authenticated user ID for authorization
            $userId = $request->user()?->id;

            if ($userId && !$this->canAccessSession($userId, $sessionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this conversation',
                ], 403);
            }

            $limit = $request->input('limit', 50);

            // Get conversation history using ConversationService with user isolation
            $messages = $this->conversationService->getConversationHistory($sessionId, $limit, $userId);

            return response()->json([
                'success' => true,
                'messages' => collect($messages)->map(function ($message) {
                    return [
                        'role' => $message['role'] ?? 'unknown',
                        'content' => $message['content'] ?? '',
                        'timestamp' => $message['timestamp'] ?? null,
                        'actions' => $message['actions'] ?? [],
                    ];
                })->values(),
                'session_id' => $sessionId,
                'total_messages' => count($messages),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear chat history for a session
     */
    public function clearHistory(ClearHistoryRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();

            // Authorization: Check if user owns this session
            $userId = $request->user()?->id ?? $dto->userId;

            if ($userId && !$this->canAccessSession($userId, $dto->sessionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized: Cannot clear this conversation',
                ], 403);
            }

            // Clear conversation using ConversationService
            $cleared = $this->conversationService->clearConversation($dto->sessionId);

            return response()->json([
                'success' => $cleared,
                'message' => $cleared ? 'Chat history cleared successfully' : 'No conversation found',
                'session_id' => $dto->sessionId,
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
                'engines' => $engines,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle streaming request
     */
    protected function handleStreamingRequest(AIRequest $aiRequest, array $validated): JsonResponse
    {
        try {
            $sessionId = $validated['session_id'];
            $message = $validated['message'];
            $userId = $validated['user_id'] ?? null;
            $engine = $validated['engine'] ?? 'openai';
            $model = $validated['model'] ?? 'gpt-4o-mini';
            $useMemory = $validated['memory'] ?? true;
            $useIntelligentRAG = $validated['use_intelligent_rag'] ?? true;
            $ragCollections = $validated['rag_collections'] ?? [];

            // Get or create conversation
            $conversationId = null;
            if ($useMemory) {
                $conversationId = $this->conversationService->getOrCreateConversation(
                    $sessionId,
                    $userId,
                    $engine,
                    $model
                );
            }

            // Load conversation history
            $messages = [];
            if ($conversationId) {
                $messages = $this->memoryOptimization->getOptimizedHistory($conversationId, 20);
            }

            // Handle RAG context if enabled
            $enhancedPrompt = $message;
            $ragMetadata = [];
            
            if ($useIntelligentRAG && $this->intelligentRAG) {
                try {
                    // Get RAG context
                    $ragResult = $this->intelligentRAG->enhancePromptWithContext(
                        $message,
                        $sessionId,
                        $ragCollections,
                        ['user_id' => $userId]
                    );
                    
                    $enhancedPrompt = $ragResult['enhanced_prompt'] ?? $message;
                    $ragMetadata = [
                        'sources' => $ragResult['sources'] ?? [],
                        'context_count' => $ragResult['context_count'] ?? 0,
                        'rag_enabled' => true,
                    ];
                } catch (\Exception $e) {
                    Log::warning('RAG enhancement failed during streaming', [
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Start streaming response
            Engine::streamResponse(
                sessionId: $sessionId,
                generator: function() use ($engine, $model, $enhancedPrompt, $messages, $conversationId, $sessionId, $userId, $ragMetadata) {
                    $fullResponse = '';
                    
                    // Use EngineBuilder for proper streaming
                    $builder = Engine::engine($engine)
                        ->model($model)
                        ->withTemperature(0.7)
                        ->withMaxTokens(1000);
                    
                    // Add conversation context
                    if (!empty($messages)) {
                        $builder->withMessages($messages);
                    }
                    
                    // Stream the response
                    foreach ($builder->generateStream($enhancedPrompt) as $chunk) {
                        $fullResponse .= $chunk;
                        yield $chunk;
                    }
                    
                    // Save to conversation history after streaming completes
                    if ($conversationId) {
                        try {
                            $conversationManager = app(\LaravelAIEngine\Services\ConversationManager::class);
                            
                            // Save user message
                            $conversationManager->addUserMessage(
                                $conversationId,
                                $enhancedPrompt,
                                ['user_id' => $userId]
                            );
                            
                            // Save assistant message with RAG metadata
                            $conversation = $conversationManager->getConversation($conversationId);
                            if ($conversation) {
                                $conversation->addMessage('assistant', $fullResponse, array_merge([
                                    'engine' => $engine,
                                    'model' => $model,
                                    'user_id' => $userId,
                                    'streaming' => true,
                                ], $ragMetadata));
                            }
                        } catch (\Exception $e) {
                            Log::error('Failed to save streaming conversation', [
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            );

            return response()->json([
                'success' => true,
                'streaming' => true,
                'session_id' => $sessionId,
                'message' => 'Streaming started',
                'rag_metadata' => $ragMetadata,
            ]);

        } catch (\Exception $e) {
            Log::error('Streaming request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Upload a file for AI processing
     */
    public function uploadFile(UploadFileRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();

            $file = $dto->file;
            $sessionId = $dto->sessionId;

            // Store the file
            $path = $file->store('ai-uploads/' . $sessionId, 'public');

            // Get file information
            $fileInfo = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => asset('storage/' . $path),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'type' => $dto->type ?? $this->detectFileType($file->getMimeType()),
            ];

            return response()->json([
                'success' => true,
                'file' => $fileInfo,
                'message' => 'File uploaded successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('File upload error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detect file type from mime type
     */
    protected function detectFileType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        } elseif (str_starts_with($mimeType, 'video/')) {
            return 'video';
        } else {
            return 'document';
        }
    }

    /**
     * Get available dynamic actions
     */
    public function getAvailableActions(Request $request): JsonResponse
    {
        try {
            $query = $request->input('query', '');

            if ($query) {
                // Get recommended actions based on query
                $actions = $this->dynamicActionService->getRecommendedActions($query);
            } else {
                // Get all available actions
                $actions = $this->dynamicActionService->discoverActions();
            }

            return response()->json([
                'success' => true,
                'actions' => $actions,
                'count' => count($actions),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute a dynamic action
     */
    public function executeDynamicAction(ExecuteDynamicActionRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();

            // Get all actions and find the requested one
            $actions = $this->dynamicActionService->discoverActions();
            $action = collect($actions)->firstWhere('id', $dto->actionId);

            if (!$action) {
                return response()->json([
                    'success' => false,
                    'error' => 'Action not found',
                ], 404);
            }

            // Execute the action
            $result = $this->dynamicActionService->executeAction(
                $action,
                $dto->parameters
            );

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get memory statistics for a session
     */
    public function getMemoryStats(string $sessionId, Request $request): JsonResponse
    {
        try {
            // SECURITY: Only use authenticated user ID for authorization
            $userId = $request->user()?->id;

            if ($userId && !$this->canAccessSession($userId, $sessionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this conversation',
                ], 403);
            }

            $messages = $this->conversationService->getConversationHistory($sessionId, 1000, $userId);

            $stats = [
                'total_messages' => count($messages),
                'user_messages' => count(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'user')),
                'assistant_messages' => count(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'assistant')),
                'system_messages' => count(array_filter($messages, fn($m) => ($m['role'] ?? '') === 'system')),
                'estimated_tokens' => $this->estimateTokens($messages),
                'session_id' => $sessionId,
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Estimate token count from messages
     */
    protected function estimateTokens(array $messages): int
    {
        $totalChars = array_reduce($messages, function ($carry, $msg) {
            return $carry + strlen($msg['content'] ?? '');
        }, 0);

        // Rough estimate: 1 token ≈ 4 characters
        return (int) ($totalChars / 4);
    }

    /**
     * Get conversation context summary
     */
    public function getContextSummary(string $sessionId, Request $request): JsonResponse
    {
        try {
            // SECURITY: Only use authenticated user ID for authorization
            $userId = $request->user()?->id;

            if ($userId && !$this->canAccessSession($userId, $sessionId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this conversation',
                ], 403);
            }

            $messages = $this->conversationService->getConversationHistory($sessionId, 50, $userId);

            if (empty($messages)) {
                return response()->json([
                    'success' => true,
                    'summary' => 'No conversation history',
                    'topics' => [],
                ]);
            }

            // Extract topics from user messages
            $userMessages = array_filter($messages, fn($m) => ($m['role'] ?? '') === 'user');
            $topics = array_map(function ($msg) {
                $content = $msg['content'] ?? '';
                return substr($content, 0, 50) . (strlen($content) > 50 ? '...' : '');
            }, array_slice($userMessages, -5));

            return response()->json([
                'success' => true,
                'summary' => 'Discussed: ' . implode(', ', $topics),
                'topics' => $topics,
                'message_count' => count($messages),
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

            // Get conversations using ConversationManager
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
            \Log::error('Get user conversations error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if user can access a session
     */
    protected function canAccessSession(string $userId, string $sessionId): bool
    {
        // Session ID format: user-{userId}-{timestamp} or custom format
        // Check if session belongs to user
        if (str_contains($sessionId, "user-{$userId}-")) {
            return true;
        }

        // Check if session is stored with user metadata
        try {
            $conversation = Engine::memory()->getConversation($sessionId);
            if ($conversation && isset($conversation['user_id'])) {
                return $conversation['user_id'] == $userId;
            }
        } catch (\Exception $e) {
            \Log::warning("Failed to check session ownership: {$e->getMessage()}");
        }

        // Allow access if authorization is disabled
        return config('ai-engine.chat.authorization.enabled', false) === false;
    }

    /**
     * Check if user can access RAG collection
     */
    protected function canAccessRAGCollection(string $userId, string $collectionName): bool
    {
        return $this->authService->canAccessCollection($userId, $collectionName);
    }
}
