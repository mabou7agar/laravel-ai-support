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
use LaravelAIEngine\Http\Requests\SendMessageRequest;

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
    public function sendMessage(SendMessageRequest $request): JsonResponse
    {
        try {
            $dto = $request->toDTO();
            
            $message = $dto->message;
            $sessionId = $dto->sessionId;
            $engine = $dto->engine;
            $model = $dto->model;
            $useMemory = $dto->memory;
            $useActions = $dto->actions;
            $useIntelligentRAG = $request->input('use_intelligent_rag', true);
            $userId = $dto->userId; // SECURITY: Uses authenticated user or demo user

            // Get RAG collections - respect user's explicit choice
            $ragCollections = $request->input('rag_collections');
            
            Log::info('RagChatApiController: rag_collections input', [
                'raw_input' => $request->input('rag_collections'),
                'is_empty' => empty($ragCollections),
                'type' => gettype($ragCollections),
            ]);
            
            if (empty($ragCollections)) {
                $ragCollections = $this->ragDiscovery->discover();
                Log::info('RagChatApiController: Auto-discovered collections', [
                    'count' => count($ragCollections),
                    'collections' => $ragCollections,
                ]);
            } else {
                Log::info('RagChatApiController: Using user-passed collections', [
                    'count' => count($ragCollections),
                    'collections' => $ragCollections,
                ]);
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

    /**
     * Analyze an uploaded file with RAG context
     *
     * @group Chat
     * @bodyParam file file required The file to analyze (PDF, TXT, DOC, DOCX, or image). Example: receipt.pdf
     * @bodyParam message string Optional message/question about the file. Example: Extract the total amount from this receipt
     * @bodyParam session_id string required Session identifier. Example: user-123
     * @bodyParam engine string AI engine to use. Example: openai
     * @bodyParam model string AI model to use. Example: gpt-4o
     *
     * @response {
     *   "success": true,
     *   "data": {
     *     "response": "I found the following information in the receipt...",
     *     "extracted_data": {...},
     *     "file_type": "image/jpeg"
     *   }
     * }
     */
    public function analyzeFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240|mimes:pdf,txt,doc,docx,png,jpg,jpeg,gif,webp',
            'message' => 'nullable|string|max:2000',
            'session_id' => 'required|string',
            'engine' => 'nullable|string',
            'model' => 'nullable|string',
            'use_intelligent_rag' => 'nullable|boolean',
            'rag_collections' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('file');
            $message = $request->input('message', 'Analyze this file and extract relevant information.');
            $sessionId = $request->input('session_id');
            $engine = $request->input('engine', 'openai');
            $model = $request->input('model', 'gpt-4o');
            $useIntelligentRAG = filter_var($request->input('use_intelligent_rag', true), FILTER_VALIDATE_BOOLEAN);
            $ragCollections = json_decode($request->input('rag_collections', '[]'), true) ?: [];
            $userId = $request->user()?->id ?? config('ai-engine.demo_user_id', '1');

            // Determine if this is an image or document
            $mimeType = $file->getMimeType();
            $isImage = str_starts_with($mimeType, 'image/');

            if ($isImage) {
                // Use vision model for image analysis
                $response = $this->analyzeImageFile($file, $message, $sessionId, $engine, $userId);
            } else {
                // Extract text and use RAG for document analysis
                $response = $this->analyzeDocumentFile($file, $message, $sessionId, $engine, $model, $useIntelligentRAG, $ragCollections, $userId);
            }

            // Store the file analysis in conversation history for follow-up questions
            $fileName = $file->getClientOriginalName();
            $fileContent = $response['file_content'] ?? '';
            
            // Add user message about the file
            $userMessage = "[Uploaded file: {$fileName}]\n\n{$message}";
            if (!empty($fileContent)) {
                $userMessage .= "\n\n--- File Content ---\n" . mb_substr($fileContent, 0, 10000); // Limit to 10k chars
            }
            
            // Get or create conversation and add messages to history
            try {
                $conversationId = $this->conversationService->getOrCreateConversation($sessionId, $userId, $engine, $model);
                $conversationManager = app(\LaravelAIEngine\Services\ConversationManager::class);
                
                // Add user message
                $conversationManager->addUserMessage($conversationId, $userMessage, [
                    'file_name' => $fileName,
                    'file_type' => $mimeType,
                    'is_file_upload' => true,
                ]);
                
                // Add assistant response (create a simple AIResponse for the manager)
                $aiResponse = new \LaravelAIEngine\DTOs\AIResponse(
                    content: $response['content'],
                    engine: new \LaravelAIEngine\Enums\EngineEnum($engine),
                    model: new \LaravelAIEngine\Enums\EntityEnum($model),
                    metadata: [
                        'file_analysis' => true,
                        'extracted_data' => $response['extracted_data'] ?? null,
                    ]
                );
                $conversationManager->addAssistantMessage($conversationId, $response['content'], $aiResponse);
            } catch (\Exception $e) {
                // Log but don't fail the request if history storage fails
                Log::warning('Failed to store file analysis in conversation history: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'response' => $response['content'],
                    'extracted_data' => $response['extracted_data'] ?? null,
                    'file_type' => $mimeType,
                    'file_name' => $fileName,
                    'sources' => $response['sources'] ?? [],
                    'rag_enabled' => $response['rag_enabled'] ?? false,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('File analysis error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Analyze an image file using vision model
     */
    protected function analyzeImageFile($file, string $message, string $sessionId, string $engine, $userId): array
    {
        // Read image as base64
        $imageData = base64_encode(file_get_contents($file->getRealPath()));
        $mimeType = $file->getMimeType();
        $dataUrl = "data:{$mimeType};base64,{$imageData}";

        // Use OpenAI client directly for vision
        $apiKey = config('ai-engine.engines.openai.api_key');
        
        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key is not configured for image analysis.');
        }

        $client = \OpenAI::client($apiKey);
        
        $systemPrompt = "You are an expert at analyzing images and extracting information. When analyzing receipts, invoices, or documents, extract all relevant data in a structured format. Be thorough and accurate. For receipts, extract: store name, date, items with prices, subtotal, tax, total, payment method if visible.";
        
        $response = $client->chat()->create([
            'model' => 'gpt-4o',
            'max_tokens' => 2000,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $message,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $content = $response->choices[0]->message->content ?? '';

        // Try to extract structured data from the response
        $extractedData = $this->tryExtractStructuredData($content);

        return [
            'content' => $content,
            'extracted_data' => $extractedData,
            'rag_enabled' => false,
            'file_content' => "[Image: {$file->getClientOriginalName()}]\n\nAI Analysis:\n{$content}",
        ];
    }

    /**
     * Analyze a document file - pass directly to AI when possible
     */
    protected function analyzeDocumentFile($file, string $message, string $sessionId, string $engine, string $model, bool $useIntelligentRAG, array $ragCollections, $userId): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $fileName = $file->getClientOriginalName();
        
        // For PDF files, try to pass directly to GPT-4o (it supports PDF via base64)
        if ($extension === 'pdf') {
            try {
                return $this->analyzeFileDirectly($file, $message, $sessionId, $engine);
            } catch (\Exception $e) {
                // Fallback to text extraction if direct analysis fails
                Log::warning('Direct PDF analysis failed, falling back to text extraction: ' . $e->getMessage());
            }
        }
        
        // For other documents or as fallback, extract text first
        $content = $this->extractFileContent($file);

        if (empty($content)) {
            // If text extraction fails for PDF, try direct analysis
            if ($extension === 'pdf') {
                return $this->analyzeFileDirectly($file, $message, $sessionId, $engine);
            }
            throw new \Exception('Could not extract text from the file. The file may be empty, corrupted, or in an unsupported format.');
        }

        // Truncate very long content to avoid token limits
        $maxContentLength = 15000;
        if (strlen($content) > $maxContentLength) {
            $content = substr($content, 0, $maxContentLength) . "\n\n[Content truncated due to length...]";
        }

        // Build prompt with file content and analysis instructions
        $fileType = strtoupper($extension);
        
        $fullMessage = "I've uploaded a {$fileType} document named '{$fileName}' with the following content:\n\n---\n{$content}\n---\n\n";
        
        // Add specific instructions based on user message or default
        if (empty($message) || $message === 'Analyze this file and extract relevant information.') {
            $fullMessage .= "Please analyze this document and extract all relevant information. If this is a receipt or invoice, extract: vendor/store name, date, line items with prices, subtotal, tax, total amount, and payment method if available. Present the data in a clear, structured format.";
        } else {
            $fullMessage .= $message;
        }

        // Use ChatService for RAG-enabled analysis
        $response = $this->chatService->processMessage(
            message: $fullMessage,
            sessionId: $sessionId,
            engine: $engine,
            model: $model,
            useMemory: true,
            useActions: false,
            useIntelligentRAG: $useIntelligentRAG,
            ragCollections: $ragCollections,
            userId: $userId
        );

        $metadata = $response->getMetadata();

        // Try to extract structured data
        $extractedData = $this->tryExtractStructuredData($response->getContent());

        return [
            'content' => $response->getContent(),
            'extracted_data' => $extractedData,
            'sources' => $metadata['sources'] ?? [],
            'rag_enabled' => $metadata['rag_enabled'] ?? false,
            'file_content' => $content, // Include file content for history storage
        ];
    }

    /**
     * Analyze file directly using OpenAI Assistants API (for PDFs)
     * Note: Only images can be passed via base64 to chat completions.
     * For PDFs, we use the Files API + Assistants API.
     */
    protected function analyzeFileDirectly($file, string $message, string $sessionId, string $engine): array
    {
        $apiKey = config('ai-engine.engines.openai.api_key');
        
        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        $fileName = $file->getClientOriginalName();
        $client = \OpenAI::client($apiKey);
        
        // Step 1: Copy file to temp location with original filename to preserve extension
        $tempDir = sys_get_temp_dir();
        $tempPath = $tempDir . DIRECTORY_SEPARATOR . $fileName;
        copy($file->getRealPath(), $tempPath);
        
        // Step 1: Upload file to OpenAI with proper filename
        $uploadedFile = $client->files()->upload([
            'purpose' => 'assistants',
            'file' => fopen($tempPath, 'r'),
        ]);
        
        // Clean up temp file
        @unlink($tempPath);
        
        $fileId = $uploadedFile->id;
        
        try {
            // Step 2: Create an assistant with file search capability
            $assistant = $client->assistants()->create([
                'name' => 'Document Analyzer',
                'instructions' => "You are an expert document analyst. Analyze uploaded files thoroughly and extract all relevant information. For receipts and invoices, extract: vendor/store name, date, line items with prices, subtotal, tax, total amount, and payment method. For other documents, provide a comprehensive summary and extract key data points. Be thorough and accurate. Always respond in a structured format.",
                'model' => 'gpt-4o',
                'tools' => [
                    ['type' => 'file_search'],
                ],
            ]);
            
            // Step 3: Create a thread with the file attached
            $thread = $client->threads()->create([
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => empty($message) || $message === 'Analyze this file and extract relevant information.'
                            ? "Please analyze this document ({$fileName}) and extract all relevant information in a structured format."
                            : $message,
                        'attachments' => [
                            [
                                'file_id' => $fileId,
                                'tools' => [['type' => 'file_search']],
                            ],
                        ],
                    ],
                ],
            ]);
            
            // Step 4: Run the assistant
            $run = $client->threads()->runs()->create($thread->id, [
                'assistant_id' => $assistant->id,
            ]);
            
            // Step 5: Wait for completion (with timeout)
            $maxAttempts = 30;
            $attempts = 0;
            while ($run->status !== 'completed' && $attempts < $maxAttempts) {
                sleep(1);
                $run = $client->threads()->runs()->retrieve($thread->id, $run->id);
                $attempts++;
                
                if (in_array($run->status, ['failed', 'cancelled', 'expired'])) {
                    throw new \Exception("Assistant run failed with status: {$run->status}");
                }
            }
            
            if ($run->status !== 'completed') {
                throw new \Exception('Assistant run timed out');
            }
            
            // Step 6: Get the response
            $messages = $client->threads()->messages()->list($thread->id);
            $content = '';
            foreach ($messages->data as $msg) {
                if ($msg->role === 'assistant') {
                    foreach ($msg->content as $contentBlock) {
                        if ($contentBlock->type === 'text') {
                            $content = $contentBlock->text->value;
                            break 2;
                        }
                    }
                }
            }
            
            // Cleanup: Delete assistant and file
            $client->assistants()->delete($assistant->id);
            $client->files()->delete($fileId);
            
            // Try to extract structured data from the response
            $extractedData = $this->tryExtractStructuredData($content);

            // Try to extract text content from PDF for history storage
            $fileContent = '';
            try {
                $fileContent = $this->extractFileContent($file);
            } catch (\Exception $e) {
                // If extraction fails, use the AI response as context
                $fileContent = "[PDF document: {$fileName}]";
            }

            return [
                'content' => $content,
                'extracted_data' => $extractedData,
                'rag_enabled' => false,
                'direct_analysis' => true,
                'file_content' => $fileContent ?: "[PDF document: {$fileName}]",
            ];
            
        } catch (\Exception $e) {
            // Cleanup file on error
            try {
                $client->files()->delete($fileId);
            } catch (\Exception $cleanupError) {
                // Ignore cleanup errors
            }
            throw $e;
        }
    }

    /**
     * Extract text content from uploaded file
     */
    protected function extractFileContent($file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->getRealPath();

        switch ($extension) {
            case 'txt':
                return file_get_contents($path);

            case 'pdf':
                // Try pdftotext command
                $output = [];
                $returnCode = 0;
                exec("pdftotext -layout " . escapeshellarg($path) . " -", $output, $returnCode);
                if ($returnCode === 0 && !empty($output)) {
                    return implode("\n", $output);
                }
                // Fallback: try Smalot PDF Parser if available
                if (class_exists(\Smalot\PdfParser\Parser::class)) {
                    $parser = new \Smalot\PdfParser\Parser();
                    $pdf = $parser->parseFile($path);
                    return $pdf->getText();
                }
                return '';

            case 'doc':
            case 'docx':
                // Try antiword for .doc
                if ($extension === 'doc') {
                    $output = [];
                    exec("antiword " . escapeshellarg($path), $output);
                    if (!empty($output)) {
                        return implode("\n", $output);
                    }
                }
                // Try PhpWord for .docx
                if (class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
                    $phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
                    $text = '';
                    foreach ($phpWord->getSections() as $section) {
                        foreach ($section->getElements() as $element) {
                            if (method_exists($element, 'getText')) {
                                $text .= $element->getText() . "\n";
                            }
                        }
                    }
                    return $text;
                }
                return '';

            default:
                return '';
        }
    }

    /**
     * Try to extract structured data from AI response
     */
    protected function tryExtractStructuredData(string $content): ?array
    {
        // Look for JSON blocks in the response
        if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $content, $matches)) {
            $jsonStr = trim($matches[1]);
            $data = json_decode($jsonStr, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        // Try to parse the entire content as JSON
        $data = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
            return $data;
        }

        // Extract key-value pairs from common patterns
        $extracted = [];
        
        // Pattern: "Key: Value" or "**Key**: Value"
        if (preg_match_all('/\*?\*?([A-Za-z\s]+)\*?\*?:\s*([^\n]+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $key = trim(strtolower(str_replace(' ', '_', $match[1])));
                $value = trim($match[2]);
                if (strlen($key) < 30 && strlen($value) < 200) {
                    $extracted[$key] = $value;
                }
            }
        }

        return !empty($extracted) ? $extracted : null;
    }
}
