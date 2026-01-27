<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Facades\Engine;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Actions\ActionManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

class ChatService
{
    public function __construct(
        protected ConversationService $conversationService,
        protected AIEngineService $aiEngineService,
        protected MemoryOptimizationService $memoryOptimization,
        protected ?IntelligentRAGService $intelligentRAG = null,
        protected ?RAGCollectionDiscovery $ragDiscovery = null,
        protected ?ActionManager $actionManager = null,

        protected ?PendingActionService $pendingActionService = null,
        protected ?IntentAnalysisService $intentAnalysisService = null,
        protected ?Chat\ChatActionHandler $chatActionHandler = null,
        protected ?Chat\ChatResponseFormatter $formatter = null,
        protected ?\LaravelAIEngine\Services\Agent\AgentOrchestrator $agentOrchestrator = null
    ) {
        // Services will be lazy-loaded when needed, not in constructor
        // This prevents circular dependency issues during service container resolution
    }

    /**
     * Lazy load ChatResponseFormatter
     */
    protected function getFormatter(): ?Chat\ChatResponseFormatter
    {
        if ($this->formatter === null && app()->bound(Chat\ChatResponseFormatter::class)) {
            $this->formatter = app(Chat\ChatResponseFormatter::class);
        }
        return $this->formatter;
    }

    /**
     * Lazy load ChatActionHandler
     */
    protected function getChatActionHandler(): ?Chat\ChatActionHandler
    {
        if ($this->chatActionHandler === null && app()->bound(Chat\ChatActionHandler::class)) {
            $this->chatActionHandler = app(Chat\ChatActionHandler::class);
        }
        return $this->chatActionHandler;
    }

    /**
     * Lazy load AgentOrchestrator
     */
    protected function getAgentOrchestrator(): ?\LaravelAIEngine\Services\Agent\AgentOrchestrator
    {
        if ($this->agentOrchestrator === null && app()->bound(\LaravelAIEngine\Services\Agent\AgentOrchestrator::class)) {
            $this->agentOrchestrator = app(\LaravelAIEngine\Services\Agent\AgentOrchestrator::class);
        }
        return $this->agentOrchestrator;
    }

    /**
     * Lazy load IntelligentRAGService
     */
    protected function getIntelligentRAG(): ?IntelligentRAGService
    {
        if ($this->intelligentRAG === null && app()->bound(IntelligentRAGService::class)) {
            $this->intelligentRAG = app(IntelligentRAGService::class);
        }
        return $this->intelligentRAG;
    }

    /**
     * Lazy load RAGCollectionDiscovery
     */
    protected function getRagDiscovery(): ?RAGCollectionDiscovery
    {
        if ($this->ragDiscovery === null && app()->bound(RAGCollectionDiscovery::class)) {
            $this->ragDiscovery = app(RAGCollectionDiscovery::class);
        }
        return $this->ragDiscovery;
    }

    /**
     * Lazy load ActionManager
     */
    protected function getActionManager(): ?ActionManager
    {
        if ($this->actionManager === null && app()->bound(ActionManager::class)) {
            $this->actionManager = app(ActionManager::class);
        }
        return $this->actionManager;
    }

    /**
     * Lazy load PendingActionService
     */
    protected function getPendingActionService(): ?PendingActionService
    {
        if ($this->pendingActionService === null && app()->bound(PendingActionService::class)) {
            $this->pendingActionService = app(PendingActionService::class);
        }
        return $this->pendingActionService;
    }

    /**
     * Lazy load IntentAnalysisService
     */
    protected function getIntentAnalysisService(): ?IntentAnalysisService
    {
        if ($this->intentAnalysisService === null && app()->bound(IntentAnalysisService::class)) {
            $this->intentAnalysisService = app(IntentAnalysisService::class);
            // Ensure dependencies are set
            if ($this->getPendingActionService()) {
                $this->intentAnalysisService->setPendingActionService($this->getPendingActionService());
            }
        }
        return $this->intentAnalysisService;
    }

    /**
     * Process a chat message and generate AI response
     *
     * @param string $message The user's message
     * @param string $sessionId Session identifier
     * @param string $engine AI engine to use
     * @param string $model AI model to use
     * @param bool $useMemory Enable conversation memory
     * @param bool $useActions Enable interactive actions
     * @param bool $useIntelligentRAG Enable RAG with access control
     * @param array $ragCollections RAG collections to search
     * @param string|int|null $userId User ID (fetched internally for access control)
     * @return AIResponse
     */
    public function processMessage(
        string $message,
        string $sessionId,
        string $engine = 'openai',
        string $model = 'gpt-4o-mini',
        bool $useMemory = true,
        bool $useActions = true,
        bool $useIntelligentRAG = true,
        array $ragCollections = [],
        $userId = null,
        ?string $searchInstructions = null
    ): AIResponse {
        Log::channel('ai-engine')->info('ChatService::processMessage called', [
            'message' => substr($message, 0, 100),
            'session_id' => $sessionId,
            'user_id' => $userId,
            'engine' => $engine,
            'model' => $model,
            'use_actions' => $useActions,
            'caller' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ]);

        // Load conversation history if memory is enabled
        $conversationId = null;
        if ($useMemory) {
            $conversationId = $this->conversationService->getOrCreateConversation(
                $sessionId,
                $userId,
                $engine,
                $model
            );
        }

        // Fire session started event
        try {
            event(new AISessionStarted(
                sessionId: $sessionId,
                userId: $userId,
                engine: $engine,
                model: $model,
                metadata: ['memory' => $useMemory, 'actions' => $useActions, 'intelligent_rag' => $useIntelligentRAG]
            ));
        } catch (\Exception $e) {
            Log::warning('Failed to fire AISessionStarted event: ' . $e->getMessage());
        }

        // Check if this message should be routed to a child node
        // Only route if: nodes enabled, routing mode, this is master node, and not already forwarded
        $nodesEnabled = config('ai-engine.nodes.enabled', false);
        $isMaster = config('ai-engine.nodes.is_master', true);
        $searchMode = config('ai-engine.nodes.search_mode', 'routing');
        $isForwarded = $this->isForwardedRequest();

        if ($nodesEnabled && $isMaster && $searchMode === 'routing' && !$isForwarded) {
            $routedResponse = $this->tryRouteToChildNode(
                $message, $sessionId, $userId, $engine, $model,
                $useMemory, $useActions, $useIntelligentRAG, $ragCollections,
                $searchInstructions, $conversationId
            );

            if ($routedResponse !== null) {
                // Track if workflow is active on child node for session continuity
                $metadata = $routedResponse->getMetadata();
                if (!empty($metadata['workflow_active']) && !empty($metadata['routed_to_node'])) {
                    Cache::put(
                        "session_node:{$sessionId}",
                        $metadata['routed_to_node'],
                        now()->addHours(1)
                    );
                } elseif (empty($metadata['workflow_active'])) {
                    // Workflow completed, clear the session-node mapping
                    Cache::forget("session_node:{$sessionId}");
                }

                return $routedResponse;
            }
        }

        // Delegate ALL routing and intelligence to AgentOrchestrator
        $orchestrator = $this->getAgentOrchestrator();
        if (!$orchestrator) {
            throw new \RuntimeException('AgentOrchestrator not available');
        }

        Log::channel('ai-engine')->info('ChatService delegating to AgentOrchestrator', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'use_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_rag' => $useIntelligentRAG,
        ]);

        // Pass options to orchestrator
        $options = [
            'engine' => $engine,
            'model' => $model,
            'use_memory' => $useMemory,
            'use_actions' => $useActions,
            'use_intelligent_rag' => $useIntelligentRAG,
            'rag_collections' => $ragCollections,
            'search_instructions' => $searchInstructions,
        ];

        $agentResponse = $orchestrator->process($message, $sessionId, $userId, $options);

        // Convert AgentResponse to AIResponse
        return new AIResponse(
            content: $agentResponse->message,
            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
            metadata: array_merge($agentResponse->context->toArray(), [
                'workflow_active' => !$agentResponse->isComplete,
                'workflow_class' => $agentResponse->context->currentWorkflow,
                'workflow_data' => $agentResponse->data ?? [],
                'workflow_completed' => $agentResponse->isComplete,
                'agent_strategy' => $agentResponse->strategy,
            ]),
            success: $agentResponse->success,
            conversationId: $conversationId
        );
    }

    /**
     * Check if this request was forwarded from another node
     * Prevents infinite forwarding loops
     */
    protected function isForwardedRequest(): bool
    {
        // Check for forwarded header or context flag
        $request = request();
        if ($request && $request->hasHeader('X-Forwarded-From-Node')) {
            return true;
        }

        return false;
    }

    /**
     * Try to route the message to a child node based on intent
     * Returns AIResponse if routed, null if should be handled locally
     */
    protected function tryRouteToChildNode(
        string $message,
        string $sessionId,
        $userId,
        string $engine,
        string $model,
        bool $useMemory,
        bool $useActions,
        bool $useIntelligentRAG,
        array $ragCollections,
        ?string $searchInstructions,
        $conversationId
    ): ?AIResponse {
        try {
            // Get NodeRouterService
            if (!app()->bound(\LaravelAIEngine\Services\Node\NodeRouterService::class)) {
                return null;
            }

            $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);

            // FIRST: Check if this session already has an active workflow on a child node
            $existingNodeSlug = Cache::get("session_node:{$sessionId}");
            if ($existingNodeSlug) {
                $existingNode = \LaravelAIEngine\Models\AINode::where('slug', $existingNodeSlug)->first();
                if ($existingNode) {
                    Log::channel('ai-engine')->info('Session has active workflow on child node, continuing there', [
                        'session_id' => $sessionId,
                        'node' => $existingNodeSlug,
                    ]);

                    // Route to the existing node
                    $routing = [
                        'node' => $existingNode,
                        'is_local' => false,
                        'reason' => "Session has active workflow on node {$existingNode->name}",
                    ];

                    // Skip to forwarding
                    return $this->forwardToNode($router, $routing, $message, $sessionId, $userId, $engine, $model, $useMemory, $useActions, $useIntelligentRAG, $ragCollections, $searchInstructions, $conversationId);
                }
            }

            // Determine routing based on message content
            $routing = $router->route($message, $ragCollections);

            // If should be handled locally, return null
            if ($routing['is_local']) {
                Log::channel('ai-engine')->debug('Message routed locally', [
                    'reason' => $routing['reason'],
                ]);
                return null;
            }

            // Forward to child node
            return $this->forwardToNode($router, $routing, $message, $sessionId, $userId, $engine, $model, $useMemory, $useActions, $useIntelligentRAG, $ragCollections, $searchInstructions, $conversationId);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Node routing failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Forward a chat message to a specific node
     */
    protected function forwardToNode(
        $router,
        array $routing,
        string $message,
        string $sessionId,
        $userId,
        string $engine,
        string $model,
        bool $useMemory,
        bool $useActions,
        bool $useIntelligentRAG,
        array $ragCollections,
        ?string $searchInstructions,
        $conversationId
    ): ?AIResponse {
        $node = $routing['node'];

        Log::channel('ai-engine')->info('Forwarding chat to child node', [
            'node' => $node->slug,
            'node_name' => $node->name,
            'reason' => $routing['reason'],
            'session_id' => $sessionId,
        ]);

        // Use node's collections if no specific collections were requested
        // This allows the child node to use its own auto-discovered collections
        $collectionsToUse = !empty($ragCollections) 
            ? $ragCollections 
            : ($routing['collections'] ?? $node->collections ?? []);

        $response = $router->forwardChat(
            $node,
            $message,
            $sessionId,
            [
                'engine' => $engine,
                'model' => $model,
                'use_memory' => $useMemory,
                'use_actions' => $useActions,
                'use_intelligent_rag' => $useIntelligentRAG,
                'rag_collections' => $collectionsToUse,
                'search_instructions' => $searchInstructions,
            ],
            $userId
        );

        if ($response['success']) {
            Log::channel('ai-engine')->info('Child node chat successful', [
                'node' => $node->slug,
                'duration_ms' => $response['duration_ms'] ?? 0,
                'credits_used' => $response['credits_used'] ?? 0,
            ]);

            // Deduct credits on master node based on child node's usage
            $creditsUsed = $response['credits_used'] ?? 0;
            if ($creditsUsed > 0 && $userId && config('ai-engine.credits.enabled', false)) {
                try {
                    $creditManager = app(\LaravelAIEngine\Services\CreditManager::class);
                    $creditManager->deductCredits(
                        (string) $userId,
                        new \LaravelAIEngine\DTOs\AIRequest(
                            prompt: $message,
                            engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                            model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                            userId: (string) $userId
                        ),
                        $creditsUsed
                    );
                    Log::channel('ai-engine')->info('Credits deducted for cross-node request', [
                        'user_id' => $userId,
                        'credits' => $creditsUsed,
                        'node' => $node->slug,
                    ]);
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('Failed to deduct credits for cross-node request', [
                        'user_id' => $userId,
                        'credits' => $creditsUsed,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return new AIResponse(
                content: $response['response'],
                engine: \LaravelAIEngine\Enums\EngineEnum::from($engine),
                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                metadata: array_merge($response['metadata'] ?? [], [
                    'routed_to_node' => $node->slug,
                    'routed_to_node_name' => $node->name,
                    'routing_reason' => $routing['reason'],
                    'credits_used' => $creditsUsed,
                ]),
                success: true,
                conversationId: $conversationId
            );
        }

        // Child node failed, fall back to local processing
        Log::channel('ai-engine')->warning('Child node chat failed, falling back to local', [
            'node' => $node->slug,
            'error' => $response['error'] ?? 'Unknown error',
        ]);

        return null;
    }

    /**
     * Legacy method - kept for backward compatibility
     * All logic now handled by AgentOrchestrator
     */

    /**
     * Get system prompt based on configuration and intent analysis
     */
    protected function getSystemPrompt(bool $useActions, $userId = null, ?array $intentAnalysis = null): string
    {
        $prompt = "You are a helpful AI assistant. Provide clear, accurate, and helpful responses to user questions.";
        $prompt .= "\n\nCRITICAL: When users provide specific names, values, or details in their request, you MUST use EXACTLY what they specified.";
        $prompt .= "\nNEVER substitute, change, or replace user-provided values with different ones from your training data or examples.";

        // Enhance prompt with intent analysis context
        if ($intentAnalysis) {
            $prompt .= "\n\n## CONTEXT FROM INTENT ANALYSIS:\n";
            $prompt .= "User Intent: {$intentAnalysis['intent']}\n";
            $prompt .= "Confidence: " . ($intentAnalysis['confidence'] * 100) . "%\n";
            $prompt .= "Context: {$intentAnalysis['context_enhancement']}\n";

            if (!empty($intentAnalysis['extracted_data'])) {
                $prompt .= "Extracted Data: " . json_encode($intentAnalysis['extracted_data']) . "\n";
            }

            // Check if there's a pending action with missing fields
            if (isset($intentAnalysis['pending_action'])) {
                $pendingAction = $intentAnalysis['pending_action'];
                $missingFields = $pendingAction['missing_fields'] ?? [];
                $modelClass = $pendingAction['model_class'] ?? null;

                if (!empty($missingFields) && $modelClass) {
                    // Get conversational guidance from model
                    $guidance = $this->getModelConversationalGuidance($modelClass);

                    if ($guidance) {
                        $prompt .= "\n\n## CONVERSATIONAL GUIDANCE:\n";
                        $prompt .= $guidance . "\n";
                    }

                    $prompt .= "\n\nIMPORTANT: User wants to create a " . class_basename($modelClass) . " but is missing required information.\n";
                    $prompt .= "Missing fields: " . implode(', ', $missingFields) . "\n";
                    $prompt .= "Ask for the missing information in a friendly, conversational way. Don't create the action yet.";
                }
            }

            // Add intent-specific instructions
            switch ($intentAnalysis['intent']) {
                case 'modify':
                    $prompt .= "\nIMPORTANT: User wants to MODIFY existing parameters. Acknowledge the changes and show updated information.";
                    break;
                case 'provide_data':
                    $prompt .= "\nIMPORTANT: User is providing ADDITIONAL DATA for optional fields. Acknowledge receipt and ask if they want to proceed.";
                    break;
                case 'question':
                    $prompt .= "\nIMPORTANT: User has a QUESTION. Provide clear explanation and ask if they're ready to proceed after answering.";
                    break;
                case 'new_request':
                    $prompt .= "\nIMPORTANT: This is a NEW REQUEST. Focus on understanding and extracting parameters for the new action.";
                    $prompt .= "\nCRITICAL: Do NOT reuse parameters from previous requests in this conversation. Each new request requires its own fresh parameters.";
                    $prompt .= "\nIf the user hasn't provided required fields (like price, quantity, etc.), ASK for them - don't assume values from previous creations.";
                    break;
            }
        }

        // Add user context if authenticated
        if ($userId && config('ai-engine.inject_user_context', true)) {
            $userContext = $this->getUserContext($userId);
            if ($userContext) {
                $prompt .= "\n\n" . $userContext;
            }
        }

        // Add numbered selection handling
        $prompt .= "\n\nIMPORTANT: When you provide numbered lists or options:";
        $prompt .= "\n- If the user responds with JUST a number (like '1', '2', etc.), they are selecting that option from your previous response";
        $prompt .= "\n- Look at your previous message and expand on the selected option";
        $prompt .= "\n- For example, if you listed '1. [Topic A]' and user says '1', provide detailed information about [Topic A]";
        $prompt .= "\n- NEVER say the question is incomplete when user sends a number - they're making a selection!";

        if ($useActions) {
            $prompt .= "\n\nIMPORTANT: You have the ability to CREATE and MANAGE data in this system.";
            $prompt .= "\n- When users ask to create records (any type), you CAN do it!";
            $prompt .= "\n- NEVER say 'I don't have information about creating...' - you have the capability to create records";
            $prompt .= "\n- When a user wants to create something, acknowledge that you can help and present the creation options";
            $prompt .= "\n- Be confident and positive about your ability to create and manage data";

            // Add available actions context
            try {
                $availableActions = $this->actionManager ? $this->actionManager->discoverActions() : [];
                if (!empty($availableActions)) {
                    $prompt .= "\n\nYou have access to the following actions in this system:\n";
                    foreach (array_slice($availableActions, 0, 10) as $action) {
                        $prompt .= "- {$action['label']}: {$action['description']}\n";
                        if (isset($action['endpoint'])) {
                            $prompt .= "  API: {$action['method']} {$action['endpoint']}\n";
                        }
                    }
                    $prompt .= "\nWhen users ask to perform these actions, you can recommend them and provide the necessary details.";
                }
            } catch (\Exception $e) {
                Log::warning('Failed to load dynamic actions: ' . $e->getMessage());
            }
        }

        return $prompt;
    }





    /**
     * Get user context for AI system prompt
     *
     * @param string|int $userId
     * @return string|null
     */
    protected function getUserContext($userId): ?string
    {
        try {
            // Get user model class from config
            $userModel = config('auth.providers.users.model', 'App\\Models\\User');

            if (!class_exists($userModel)) {
                return null;
            }

            // Fetch user with caching (5 minutes)
            $user = \Illuminate\Support\Facades\Cache::remember(
                "ai_user_context_{$userId}",
                300,
                fn() => $userModel::find($userId)
            );

            if (!$user) {
                return null;
            }

            // Build user context
            $context = "USER CONTEXT:\n";

            // User ID (always include for data searching)
            $context .= "- User ID: {$user->id}\n";

            // Name
            if (isset($user->name)) {
                $context .= "- User's name: {$user->name}\n";
            }

            // Email (always include for data searching)
            if (isset($user->email)) {
                $context .= "- Email: {$user->email}\n";
            }

            // Phone number
            if (isset($user->phone)) {
                $context .= "- Phone: {$user->phone}\n";
            } elseif (isset($user->phone_number)) {
                $context .= "- Phone: {$user->phone_number}\n";
            } elseif (isset($user->mobile)) {
                $context .= "- Phone: {$user->mobile}\n";
            }

            // Additional useful fields
            if (isset($user->username)) {
                $context .= "- Username: {$user->username}\n";
            }

            if (isset($user->first_name) && isset($user->last_name)) {
                $context .= "- Full Name: {$user->first_name} {$user->last_name}\n";
            }

            if (isset($user->title) || isset($user->job_title)) {
                $title = $user->title ?? $user->job_title;
                $context .= "- Job Title: {$title}\n";
            }

            if (isset($user->department)) {
                $context .= "- Department: {$user->department}\n";
            }

            if (isset($user->location) || isset($user->city)) {
                $location = $user->location ?? $user->city;
                $context .= "- Location: {$location}\n";
            }

            if (isset($user->timezone)) {
                $context .= "- Timezone: {$user->timezone}\n";
            }

            if (isset($user->language) || isset($user->locale)) {
                $language = $user->language ?? $user->locale;
                $context .= "- Language: {$language}\n";
            }

            // Role/Admin status
            if (isset($user->is_admin) && $user->is_admin) {
                $context .= "- Role: Administrator (has full system access)\n";
            } elseif (method_exists($user, 'getRoleNames')) {
                // Spatie Laravel Permission
                $roles = $user->getRoleNames();
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            } elseif (method_exists($user, 'roles')) {
                // Generic roles relationship
                $roles = $user->roles()->pluck('name');
                if ($roles->isNotEmpty()) {
                    $context .= "- Role: " . $roles->join(', ') . "\n";
                }
            }

            // Tenant/Organization
            if (isset($user->tenant_id)) {
                $context .= "- Organization ID: {$user->tenant_id}\n";
            } elseif (isset($user->organization_id)) {
                $context .= "- Organization ID: {$user->organization_id}\n";
            } elseif (isset($user->company_id)) {
                $context .= "- Company ID: {$user->company_id}\n";
            }

            // Custom user context (if method exists)
            if (method_exists($user, 'getAIContext')) {
                $customContext = $user->getAIContext();
                if ($customContext) {
                    $context .= $customContext . "\n";
                }
            }

            $context .= "\nIMPORTANT INSTRUCTIONS:\n";
            $context .= "- Always address the user by their name when appropriate\n";
            $context .= "- When searching for user's data, use their User ID ({$user->id}) or Email ({$user->email})\n";
            $context .= "- Personalize responses based on their role and context\n";
            $context .= "- When user asks 'my emails', 'my documents', etc., search for data belonging to User ID: {$user->id}";

            return $context;

        } catch (\Exception $e) {
            Log::warning('Failed to get user context: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get optional parameters for an action
     */
    protected function getOptionalParamsForAction(\LaravelAIEngine\DTOs\InteractiveAction $action): array
    {
        // Get action definition from ActionManager to find optional params
        $actionId = $action->data['action'] ?? null;
        if (!$actionId || !$this->actionManager) {
            return [];
        }

        // Optional params should be defined in model's AI config, not hardcoded here
        return [];
    }

    /**
     * Generate prompt for optional parameters with AI suggestions
     */
    /**
     * Generate prompt for optional parameters - AI Native version
     */
    protected function generateOptionalParamsPrompt(\LaravelAIEngine\DTOs\InteractiveAction $action): ?string
    {
        $optionalParams = $this->getOptionalParamsForAction($action);

        if (empty($optionalParams)) {
            return null;
        }

        $currentParams = $action->data['params'] ?? [];
        $missingOptional = array_filter($optionalParams, fn($param) => !isset($currentParams[$param]));

        if (empty($missingOptional)) {
            return null;
        }

        // AI-Native Approach: Just tell the AI what's missing and let it phrase the question
        $prompt = "\n\nCONTEXT: The following optional fields are available but not yet provided: " . implode(', ', $missingOptional) . ".";
        $prompt .= "\nINSTRUCTION: Ask the user if they would like to provide any of these optional details, or if they want to proceed as is.";

        return $prompt;
    }

    /**
     * Generate data summary for confirmation using AI-driven formatting
     */
    protected function generateDataSummary(\LaravelAIEngine\DTOs\InteractiveAction $action): string
    {
        $params = $action->data['params'] ?? [];
        $modelClass = $action->data['model_class'] ?? '';
        $modelName = class_basename($modelClass);

        // Apply model's normalization before generating summary
        if (method_exists($modelClass, 'normalizeAIData')) {
            try {
                $reflection = new \ReflectionMethod($modelClass, 'normalizeAIData');
                $reflection->setAccessible(true);
                $params = $reflection->invoke(null, $params);
            } catch (\Exception $e) {
                // If normalization fails, continue with original params
            }
        }

        // Use AI to generate formatted summary from normalized data
        try {
            $prompt = "Format the following {$modelName} data into a clear, user-friendly confirmation summary.\n\n";
            $prompt .= "Data:\n" . json_encode($params, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "Requirements:\n";
            $prompt .= "- Start with '**Summary of Information:**'\n";
            $prompt .= "- Intelligently display relevant information based on the data structure:\n";
            $prompt .= "  * If there's entity info (person/organization), show relevant contact details\n";
            $prompt .= "  * If there are collection arrays, show them in a numbered list with details\n";
            $prompt .= "  * For array fields, show nested values INSIDE the array items, not as top-level fields\n";
            $prompt .= "  * If there are dates (created, issued, due, scheduled), show meaningful ones\n";
            $prompt .= "  * If there's a total/amount/price, display it\n";
            $prompt .= "- SKIP internal/technical fields:\n";
            $prompt .= "  * id, user_id, workspace, created_by, account_id, category_id\n";
            $prompt .= "  * Any field ending in _id (except meaningful references)\n";
            $prompt .= "  * Fields like: account_type, module names, display flags\n";
            $prompt .= "  * _resolve_relationships and other internal metadata\n";
            $prompt .= "  * Any field with value 0 or null that's not meaningful\n";
            $prompt .= "- Format currency values with $ symbol\n";
            $prompt .= "- Use bold for section headers\n";
            $prompt .= "- Keep it clean, concise, and user-friendly\n";
            $prompt .= "- Adapt the format to the type of data (works for any model type)\n";
            $prompt .= "- End with: '**Please review the information above.**\\nReply 'yes' to create, or tell me what you'd like to change.'\n\n";
            $prompt .= "Generate the formatted summary now:";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from(config('ai-engine.actions.intent_model', 'gpt-3.5-turbo')),
                maxTokens: 500,
                temperature: 0
            );

            $response = $this->aiEngineService->generate($aiRequest);
            return $response->getContent();

        } catch (\Exception $e) {
            // Fallback to simple formatting if AI fails
            return $this->generateSimpleSummary($params, $modelClass);
        }
    }

    /**
     * Fallback simple summary generation
     */
    protected function generateSimpleSummary(array $params, string $modelClass): string
    {
        $fieldDefinitions = $this->getModelFieldDefinitions($modelClass);
        $summary = "**Summary of Information:**\n\n";

        foreach ($params as $key => $value) {
            if ($key === '_resolve_relationships') {
                continue;
            }

            if ($key === 'quantity' && isset($params['items']) && is_array($params['items'])) {
                continue;
            }

            $fieldDef = $fieldDefinitions[$key] ?? null;

            if (is_array($value)) {
                $summary .= $this->formatArrayField($key, $value, $fieldDef);
            } else {
                $summary .= $this->formatScalarField($key, $value, $fieldDef);
            }
        }

        $summary .= "\n**Please review the information above.**\nReply 'yes' to create, or tell me what you'd like to change.";

        return $summary;
    }

    /**
     * Get field definitions from model
     */
    protected function getModelFieldDefinitions(string $modelClass): array
    {
        $definitions = [];

        try {
            // Try getFunctionSchema first (most detailed)
            if (method_exists($modelClass, 'getFunctionSchema')) {
                $schema = $modelClass::getFunctionSchema();
                $properties = $schema['parameters']['properties'] ?? [];

                foreach ($properties as $fieldName => $fieldSchema) {
                    $definitions[$fieldName] = [
                        'type' => $fieldSchema['type'] ?? 'string',
                        'description' => $fieldSchema['description'] ?? null,
                        'format' => $fieldSchema['format'] ?? null,
                        'items' => $fieldSchema['items'] ?? null,
                    ];
                }
            }
            // Fallback to initializeAI
            elseif (method_exists($modelClass, 'initializeAI')) {
                $config = (new $modelClass)->initializeAI();
                $fields = $config['fields'] ?? [];

                foreach ($fields as $fieldName => $fieldInfo) {
                    if (is_array($fieldInfo)) {
                        $definitions[$fieldName] = $fieldInfo;
                    }
                }
            }
        } catch (\Exception $e) {
            // Fallback to empty definitions
        }

        return $definitions;
    }

    /**
     * Format array field dynamically based on definition
     */
    protected function formatArrayField(string $key, array $value, ?array $fieldDef): string
    {
        if (empty($value)) {
            return '';
        }

        $context = [];
        $label = $fieldDef['description'] ?? $this->getFormatter()->formatFieldLabel($key, $context);
        $output = "**{$label}:**\n";

        // Get item schema if available
        $itemSchema = $fieldDef['items']['properties'] ?? null;

        foreach ($value as $index => $item) {
            $itemNum = $index + 1;

            if (is_array($item)) {
                // Get primary field (name, title, item, etc.)
                $primaryField = $item['name'] ?? $item['item'] ?? $item['title'] ?? $item['product_name'] ?? 'Item';
                $output .= "{$itemNum}. {$primaryField}\n";

                // Display all other fields dynamically from the item
                foreach ($item as $fieldKey => $fieldValue) {
                    // Skip primary fields and description
                    if (in_array($fieldKey, ['name', 'item', 'title', 'product_name', 'description'])) {
                        continue;
                    }

                    // Get field info from schema
                    $fieldType = $itemSchema[$fieldKey]['type'] ?? null;
                    $context = [];
                    $fieldLabel = $itemSchema[$fieldKey]['description'] ?? $this->getFormatter()->formatFieldLabel($fieldKey, $context);

                    // Format based on type
                    if ($fieldType === 'number' || in_array($fieldKey, ['price', 'amount', 'cost', 'total'])) {
                        $output .= "   - {$fieldLabel}: $" . number_format($fieldValue, 2) . "\n";
                    } elseif ($fieldType === 'integer' || in_array($fieldKey, ['quantity', 'qty', 'count'])) {
                        $output .= "   - {$fieldLabel}: {$fieldValue}\n";
                    } else {
                        $output .= "   - {$fieldLabel}: {$fieldValue}\n";
                    }
                }
            } else {
                // Simple value
                $output .= "{$itemNum}. {$item}\n";
            }
        }

        return $output;
    }

    /**
     * Format scalar field based on definition
     */
    protected function formatScalarField(string $key, $value, ?array $fieldDef): string
    {
        $fieldType = $fieldDef['type'] ?? 'string';
        $context = [];
        $label = $fieldDef['description'] ?? $this->getFormatter()->formatFieldLabel($key, $context);

        // Format based on type
        if ($fieldType === 'number' || in_array($key, ['price', 'total', 'amount', 'cost'])) {
            return "- **{$label}:** $" . number_format($value, 2) . "\n";
        } elseif ($fieldType === 'boolean') {
            return "- **{$label}:** " . ($value ? 'Yes' : 'No') . "\n";
        } elseif ($fieldType === 'date' || str_ends_with($key, '_date')) {
            return "- **{$label}:** " . date('Y-m-d', strtotime($value)) . "\n";
        } else {
            return "- **{$label}:** {$value}\n";
        }
    }

    /**
     * Generate AI suggestions for optional parameters
     */
    protected function generateOptionalParamSuggestions(array $currentParams, array $optionalParams): array
    {
        $suggestions = [];

        if (!$this->aiEngineService || empty($optionalParams)) {
            return $suggestions;
        }

        // AI suggestion generation should be model-driven, not hardcoded
        // Models can implement their own suggestion logic if needed
        try {
            $entityName = $currentParams['name'] ?? 'Record';

            // Generic prompt that works for any entity type
            $fieldDescriptions = array_map(function ($param) {
                return "- {$param}: Provide a realistic value for this field";
            }, $optionalParams);

            $prompt = "Based on this record: \"{$entityName}\"\n\n";
            $prompt .= "Generate intelligent, realistic suggestions for these fields:\n";
            $prompt .= implode("\n", $fieldDescriptions) . "\n\n";
            $prompt .= "Return ONLY a valid JSON object with these exact keys.";

            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                systemPrompt: 'You are a data assistant. Generate professional, realistic information. Return valid JSON only.',
                maxTokens: 400
            );

            $response = $this->aiEngineService->generate($aiRequest);
            $content = trim($response->getContent());

            // Try to extract JSON if wrapped in markdown
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }

            $result = json_decode($content, true);

            if (is_array($result) && !empty($result)) {
                // Filter to only include requested params
                $suggestions = array_intersect_key($result, array_flip($optionalParams));

                Log::channel('ai-engine')->info('Generated AI suggestions', [
                    'entity' => $entityName,
                    'suggestions' => $suggestions
                ]);
            } else {
                Log::warning('AI suggestions returned invalid JSON', [
                    'content' => $content
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to generate optional param suggestions: ' . $e->getMessage());
        }

        return $suggestions;
    }

    /**
     * Check if message contains optional parameter values
     */
    protected function hasOptionalParamValues(string $message): bool
    {
        // Check if message contains common patterns for providing values
        $patterns = [
            '/sku[:\s]+/i',
            '/description[:\s]+/i',
            '/price[:\s]+\$?\d+/i',
            '/category[:\s]+/i',
            '/stock[:\s]+\d+/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract optional parameters from user message
     */
    protected function extractOptionalParamsFromMessage(string $message, array $optionalParams): array
    {
        $extracted = [];

        // Use AI to extract optional parameters
        if ($this->aiEngineService && !empty($optionalParams)) {
            try {
                $prompt = "Extract the following optional fields from the message:\n";
                $prompt .= "Fields: " . implode(', ', $optionalParams) . "\n\n";
                $prompt .= "Message: {$message}\n\n";
                $prompt .= "Return ONLY a JSON object with the extracted values. Use null for missing fields.\n";
                $prompt .= "Format: {\"field_name\": \"value\"}\n";
                $prompt .= "IMPORTANT: Extract ONLY actual values from the user's message, not placeholder examples.\n";
                $prompt .= "CRITICAL: Do NOT use values from conversation history or previous messages. Extract ONLY from this specific message.\n";

                $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    systemPrompt: 'You are a data extraction assistant.',
                    maxTokens: 200
                );

                $response = $this->aiEngineService->generate($aiRequest);
                $result = json_decode($response->getContent(), true);

                if (is_array($result)) {
                    $extracted = array_filter($result, fn($v) => $v !== null);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to extract optional params: ' . $e->getMessage());
            }
        }

        return $extracted;
    }

    /**
     * Check if message is a confirmation (backward compatibility)
     */
    protected function isConfirmationMessage(string $message): bool
    {
        // Use the IntentAnalysisService directly
        $analysis = app(\LaravelAIEngine\Services\IntentAnalysisService::class)->analyzeMessageIntent($message);
        return $analysis['intent'] === 'confirm';
    }

    /**
     * Get pending actions from conversation history
     */
    protected function getPendingActionsFromHistory(array $conversationHistory): array
    {
        // Look for the last user message that triggered an action
        for ($i = count($conversationHistory) - 1; $i >= 0; $i--) {
            $message = $conversationHistory[$i];

            if (($message['role'] ?? '') === 'user') {
                $content = $message['content'] ?? '';

                // Check if this message could trigger an action (any non-trivial message)
                if (!empty(trim($content)) && strlen($content) > 5) {
                    // Re-generate the action from this message
                    if ($this->actionManager) {
                        try {
                            $context = [
                                'conversation_history' => $conversationHistory,
                                'user_id' => $message['user_id'] ?? null,
                                'session_id' => $message['session_id'] ?? null,
                            ];
                            $actions = $this->getActionManager()->generateActionsForContext($content, $context, null);

                            if (!empty($actions)) {
                                Log::channel('ai-engine')->info('Retrieved pending action from history', [
                                    'action' => $actions[0]->label,
                                    'from_message' => substr($content, 0, 50)
                                ]);
                                return $actions;
                            }
                        } catch (\Exception $e) {
                            Log::warning('Failed to retrieve pending actions: ' . $e->getMessage());
                        }
                    }

                    break;
                }
            }
        }

        return [];
    }

    /**
     * Check if any smart action should be auto-executed
     */
    protected function checkAutoExecuteAction(array $smartActions, string $message, string $sessionId = null): ?\LaravelAIEngine\DTOs\InteractiveAction
    {
        $messageLower = strtolower($message);

        // Check for confirmation keywords
        $confirmKeywords = ['yes', 'confirm', 'do it', 'go ahead', 'proceed', 'create it', 'make it'];
        $isConfirmation = false;

        foreach ($confirmKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $isConfirmation = true;
                break;
            }
        }

        // If user is confirming, check for pending actions in cache
        if ($isConfirmation && $sessionId) {
            // Check singular cache key first (current storage format)
            $cacheKey = "pending_action_{$sessionId}";
            $cachedActionData = Cache::get($cacheKey);

            if ($cachedActionData) {
                Log::channel('ai-engine')->info('Auto-executing pending action from cache', [
                    'action' => $cachedActionData['label'],
                    'cache_key' => $cacheKey,
                ]);

                // Convert array back to InteractiveAction
                return new \LaravelAIEngine\DTOs\InteractiveAction(
                    id: $cachedActionData['id'],
                    type: \LaravelAIEngine\Enums\ActionTypeEnum::from($cachedActionData['type']),
                    label: $cachedActionData['label'],
                    description: $cachedActionData['description'] ?? '',
                    data: $cachedActionData['data']
                );
            }

            // Fallback: check plural cache key for backward compatibility
            $cacheKey = "pending_actions_{$sessionId}";
            $pendingActions = Cache::get($cacheKey, []);

            // If there are multiple pending actions, execute the most recent one
            if (!empty($pendingActions)) {
                $mostRecentAction = end($pendingActions);

                Log::channel('ai-engine')->info('Auto-executing most recent pending action from legacy cache', [
                    'action' => $mostRecentAction['label'],
                    'total_pending' => count($pendingActions),
                ]);

                // Convert array back to InteractiveAction
                return new \LaravelAIEngine\DTOs\InteractiveAction(
                    id: $mostRecentAction['id'],
                    type: \LaravelAIEngine\Enums\ActionTypeEnum::from(\LaravelAIEngine\Enums\ActionTypeEnum::BUTTON),
                    label: $mostRecentAction['label'],
                    description: '',
                    data: $mostRecentAction['data']
                );
            }
        }

        // Fallback to checking current smart actions
        if ($isConfirmation) {
            foreach ($smartActions as $action) {
                if (isset($action->data['ready_to_execute']) && $action->data['ready_to_execute']) {
                    return $action;
                }
            }
        }

        return null;
    }

    /**
     * Execute smart action inline
     */
    /**
     * Execute smart action inline
     */
    protected function executeSmartActionInline(\LaravelAIEngine\DTOs\InteractiveAction $action, $userId): array
    {
        // Delegate to ChatActionHandler
        if ($handler = $this->getChatActionHandler()) {
            return $handler->executeSmartAction($action, $userId);
        }

        return ['success' => false, 'error' => 'ChatActionHandler not available'];
    }

    /**
     * Execute remote model action on a remote node
     */
    // executeRemoteModelAction and executeDynamicModelAction removed as they are handled by ChatActionHandler


    /**
     * Remove executed action from pending list
     */
    protected function removeExecutedAction(string $sessionId, string $actionId): void
    {
        $cacheKey = "pending_actions_{$sessionId}";
        $pendingActions = Cache::get($cacheKey, []);

        // Remove the executed action
        $pendingActions = array_filter($pendingActions, function ($action) use ($actionId) {
            return $action['id'] !== $actionId;
        });

        // Re-index array
        $pendingActions = array_values($pendingActions);

        // Update cache
        if (empty($pendingActions)) {
            Cache::forget($cacheKey);
        } else {
            Cache::put($cacheKey, $pendingActions, 86400);
        }

        Log::channel('ai-engine')->info('Removed executed action from pending list', [
            'session_id' => $sessionId,
            'action_id' => $actionId,
            'remaining' => count($pendingActions),
        ]);
    }

    /**
     * Get count of pending actions for a session
     */
    protected function getPendingActionsCount(string $sessionId): int
    {
        $cacheKey = "pending_actions_{$sessionId}";
        $pendingActions = Cache::get($cacheKey, []);
        return count($pendingActions);
    }

    /**
     * Execute email reply
     */
    protected function executeEmailReply(array $params, $userId): array
    {
        // TODO: Integrate with email service
        Log::channel('ai-engine')->info('Email reply would be sent', [
            'params' => $params,
            'user_id' => $userId
        ]);

        return [
            'success' => true,
            'message' => '✅ Email reply sent successfully!',
            'data' => $params
        ];
    }

    /**
     * Execute task creation
     */
    protected function executeTaskCreation(array $params, $userId): array
    {
        // TODO: Integrate with task service
        Log::channel('ai-engine')->info('Task would be created', [
            'params' => $params,
            'user_id' => $userId
        ]);

        return [
            'success' => true,
            'message' => '✅ Task created successfully!',
            'data' => $params
        ];
    }

    /**
     * Get conversational guidance from model's AI config
     */
    protected function getModelConversationalGuidance(string $modelClass): ?string
    {
        try {
            if (!class_exists($modelClass)) {
                return null;
            }

            $reflection = new \ReflectionClass($modelClass);

            if (!$reflection->hasMethod('initializeAI')) {
                return null;
            }

            $method = $reflection->getMethod('initializeAI');
            if (!$method->isStatic()) {
                return null;
            }

            $config = $modelClass::initializeAI();
            $description = $config['description'] ?? '';

            // Extract conversational guidance section
            if (preg_match('/CONVERSATIONAL GUIDANCE:(.*?)(?=\n\n[A-Z]|$)/s', $description, $matches)) {
                return trim($matches[1]);
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to get conversational guidance', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Check if message looks like an action/workflow request
     */
    protected function looksLikeActionRequest(string $message): bool
    {
        // Check for common action patterns
        $actionPatterns = [
            '/^(create|make|add|new|build|generate)\s+/i',
            '/^(update|edit|modify|change)\s+/i',
            '/^(delete|remove)\s+/i',
            '/^(find|search|get|show|list)\s+/i',
        ];

        foreach ($actionPatterns as $pattern) {
            if (preg_match($pattern, trim($message))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user-friendly error message based on AI error
     */
    protected function getErrorMessage(?string $error): string
    {
        if (!$error) {
            return config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
        }

        $showDetailed = config('ai-engine.error_handling.show_detailed_errors', false);
        $showQuota = config('ai-engine.error_handling.show_quota_errors', true);
        $errorMessages = config('ai-engine.error_handling.error_messages', []);

        // Check for specific error types
        if (str_contains(strtolower($error), 'quota') || str_contains(strtolower($error), 'exceeded')) {
            return $showQuota
                ? ($errorMessages['quota_exceeded'] ?? 'AI service quota exceeded.')
                : config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
        }

        if (str_contains(strtolower($error), 'rate limit')) {
            return $errorMessages['rate_limit'] ?? 'Too many requests. Please try again later.';
        }

        if (str_contains(strtolower($error), 'api key') || str_contains(strtolower($error), 'authentication')) {
            return $errorMessages['invalid_api_key'] ?? 'AI service configuration error.';
        }

        if (str_contains(strtolower($error), 'timeout')) {
            return $errorMessages['timeout'] ?? 'AI service request timed out.';
        }

        if (str_contains(strtolower($error), 'model') && str_contains(strtolower($error), 'not found')) {
            return $errorMessages['model_not_found'] ?? 'The requested AI model is not available.';
        }

        if (str_contains(strtolower($error), 'network') || str_contains(strtolower($error), 'connection')) {
            return $errorMessages['network_error'] ?? 'Unable to connect to AI service.';
        }

        // Return detailed error if enabled, otherwise fallback
        return $showDetailed
            ? $error
            : config('ai-engine.error_handling.fallback_message', 'AI service is temporarily unavailable.');
    }

    /**
     * Apply AI mapper to transform data before execution
     * Checks if model has mapAIData method and calls it
     */
    protected function applyAIMapper(string $modelClass, array $params): array
    {
        try {
            // Check if model exists and has mapAIData method
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);

                if ($reflection->hasMethod('mapAIData')) {
                    $method = $reflection->getMethod('mapAIData');

                    Log::channel('ai-engine')->info('Applying AI data mapper', [
                        'model' => $modelClass,
                        'original_params' => $params
                    ]);

                    // Call the mapper method
                    if ($method->isStatic()) {
                        $mappedParams = $modelClass::mapAIData($params);
                    } else {
                        $model = new $modelClass();
                        $mappedParams = $model->mapAIData($params);
                    }

                    Log::channel('ai-engine')->info('AI data mapper applied', [
                        'model' => $modelClass,
                        'mapped_params' => $mappedParams
                    ]);

                    return $mappedParams;
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI mapper failed, using original params', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }

        // Return original params if no mapper or mapper failed
        return $params;
    }

    /**
     * Get action definition by action ID
     */
    protected function getActionDefinition(?string $actionId): ?array
    {
        if (!$actionId || !$this->actionManager) {
            return null;
        }

        try {
            // Use discoverActions() which calls ActionRegistry::all()
            $availableActions = $this->actionManager->discoverActions();
            foreach ($availableActions as $action) {
                if (($action['id'] ?? null) === $actionId) {
                    return $action;
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return null;
    }



}
