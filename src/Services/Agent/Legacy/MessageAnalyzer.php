<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\IntentAnalysisService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\WorkflowDiscoveryService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Intelligent message analyzer that determines routing
 * Replaces ComplexityAnalyzer with smarter, context-aware analysis
 */
class MessageAnalyzer
{
    public function __construct(
        protected IntentAnalysisService $intentAnalysis,
        protected AIEngineService $aiEngine,
        protected ?WorkflowDiscoveryService $workflowDiscovery = null
    ) {
        // Lazy load workflow discovery if not injected
        if ($this->workflowDiscovery === null && app()->bound(WorkflowDiscoveryService::class)) {
            $this->workflowDiscovery = app(WorkflowDiscoveryService::class);
        }
    }

    /**
     * Analyze message to determine routing using pure AI intelligence
     * Uses a single unified AI call to detect intent, follow-ups, and entity context
     */
    public function analyze(string $message, UnifiedActionContext $context): array
    {
        // PRIORITY 0: Check if session is already routed to a remote node
        $routedNode = $context->get('routed_to_node');
        if ($routedNode && isset($routedNode['node_id'])) {
            Log::channel('ai-engine')->info('Session already routed to remote node', [
                'session_id' => $context->sessionId,
                'node' => $routedNode['node_name'] ?? 'Unknown',
            ]);
            return [
                'type' => 'remote_routing',
                'action' => 'route_to_remote_node',
                'confidence' => 0.99,
                'reasoning' => 'Continuing session on remote node',
                'target_node' => $routedNode,
                'collector_name' => $routedNode['collector_name'] ?? null,
            ];
        }

        // PRIORITY 1: Check active autonomous collector
        if ($context->get('autonomous_collector')) {
            Log::channel('ai-engine')->info('Active autonomous collector detected', [
                'session_id' => $context->sessionId,
            ]);
            return [
                'type' => 'autonomous_collector',
                'action' => 'continue_autonomous_collector',
                'confidence' => 0.99,
                'reasoning' => 'Active autonomous collector session'
            ];
        }

        // PRIORITY 2: Check active workflow context
        if ($context->currentWorkflow) {
            return $this->analyzeInWorkflowContext($message, $context);
        }

        // PRIORITY 3: Check for autonomous collector triggers (with permission check)
        $userId = $context->userId ?? null;
        $collectorMatch = \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::findConfigForMessage($message, $userId);
        if ($collectorMatch) {
            Log::channel('ai-engine')->info('Autonomous collector trigger detected', [
                'message' => substr($message, 0, 100),
            ]);
            return [
                'type' => 'autonomous_collector',
                'action' => 'start_autonomous_collector',
                'confidence' => 0.95,
                'reasoning' => 'Message matches autonomous collector trigger',
                'collector_match' => $collectorMatch, // Pass the match to avoid duplicate AI call
            ];
        }

        // PRIORITY 4: Check if remote nodes can handle this request
        if (config('ai-engine.nodes.enabled', false)) {
            $remoteRouting = $this->checkRemoteNodeCapabilities($message, $context);
            if ($remoteRouting) {
                return $remoteRouting;
            }
        }

        // PRIORITY 5: Use unified AI to intelligently route the message
        // This single AI call handles: intent detection, follow-up detection, entity context
        return $this->analyzeWithUnifiedAI($message, $context);
    }

    /**
     * Analyze message in workflow context using AI intelligence
     */
    protected function analyzeInWorkflowContext(string $message, UnifiedActionContext $context): array
    {
        $awaitingConfirmation = $context->get('awaiting_confirmation');
        $askingFor = $context->get('asking_for');

        // If awaiting confirmation or asking for field, assume workflow continuation
        // The workflow handler will use AI to interpret the response
        if ($awaitingConfirmation || $askingFor) {
            return [
                'type' => 'workflow_continuation',
                'action' => 'continue_workflow',
                'confidence' => 0.95,
                'reasoning' => 'Continuing active workflow - AI will interpret response'
            ];
        }

        // Default: workflow continuation
        return [
            'type' => 'workflow_continuation',
            'action' => 'continue_workflow',
            'confidence' => 0.9,
            'reasoning' => 'Active workflow - continuing'
        ];
    }

    /**
     * Simplified routing analysis - determines which handler to use
     * Defers detailed decision-making to AutonomousRAGAgent which has better context
     *
     * This method only needs to decide:
     * 1. Is this a data operation? (CRUD, query, aggregate) → knowledge_search (AutonomousRAGAgent)
     * 2. Is this general chat? → conversational
     */
    protected function analyzeWithUnifiedAI(string $message, UnifiedActionContext $context): array
    {
        // Get context information
        $recentContext = $this->getRecentConversationContext($context);
        $lastEntity = $recentContext['last_entity'];
        $lastNode = $recentContext['last_node'];
        $conversationContext = $this->buildConversationContextString($context);

        try {
            // Simple prompt - just detect if this is a CRUD operation or data query
            // AutonomousRAGAgent will handle the detailed routing for queries
            $prompt = "";

            // Include conversation context if available
            if (!empty($conversationContext)) {
                $prompt .= "Recent conversation:\n{$conversationContext}\n\n";
            }

            $prompt .= "User message: \"{$message}\"\n\n";
            $prompt .= "What type of request is this? Respond with ONE word:\n";
            $prompt .= "- create: User explicitly wants to CREATE/MAKE/ADD/BUILD something NEW (e.g., 'create invoice', 'make a bill')\n";
            $prompt .= "- update: User explicitly wants to UPDATE/EDIT/MODIFY existing data\n";
            $prompt .= "- delete: User explicitly wants to DELETE/REMOVE something\n";
            $prompt .= "- query: User wants to VIEW/SHOW/LIST/SEARCH/COUNT/TOTAL/ASK about data (includes follow-up questions)\n";
            $prompt .= "- chat: Just greeting/chatting/asking about capabilities\n\n";
            $prompt .= "Answer (create/update/delete/query/chat):";

            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 10,
                temperature: 0
            );

            $response = $this->aiEngine->generate($request);
            $intent = strtolower(trim($response->getContent()));

            // Clean up response
            $intent = preg_replace('/[^a-z]/', '', $intent);

            Log::channel('ai-engine')->info('Simplified routing analysis', [
                'message' => $message,
                'intent' => $intent,
                'has_conversation_context' => !empty($conversationContext),
                'last_entity' => $lastEntity,
            ]);

            // Handle ALL data operations (CRUD + queries) through AutonomousRAGAgent
            // AutonomousRAGAgent will decide whether to use:
            // - model_tool for CRUD operations (create/update/delete)
            // - db_query for read operations
            // - db_count for counting
            // - etc.
            if (in_array($intent, ['create', 'update', 'delete', 'query'])) {
                return [
                    'type' => 'knowledge_search',
                    'action' => 'search_knowledge',
                    'operation' => $intent,
                    'crud_operation' => $intent,
                    'confidence' => 0.85,
                    'reasoning' => $intent === 'query' 
                        ? "Data query - AutonomousRAGAgent will decide the tool"
                        : "CRUD operation - AutonomousRAGAgent will use model_tool"
                ];
            }

            // Only chat/greeting goes to conversational
            if ($intent === 'chat') {
                return [
                    'type' => 'conversational',
                    'action' => 'chat',
                    'confidence' => 0.85,
                    'reasoning' => 'General chat or greeting',
                    'context_entity' => $lastEntity,
                    'conversation_context' => $conversationContext,
                ];
            }

            // Default: conversational (chat, greetings, etc.)
            return [
                'type' => 'conversational',
                'action' => 'handle_conversational',
                'confidence' => 0.6,
                'reasoning' => 'General conversation'
            ];

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Routing analysis failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback to legacy analysis
            return $this->analyzeForWorkflowFallback($message, $context);
        }
    }

    /**
     * Fallback analysis when routing AI fails
     */
    protected function analyzeForWorkflowFallback(string $message, UnifiedActionContext $context): array
    {
        // Simple pattern-based fallback
        $query = strtolower($message);

        // Check for data operation patterns (queries, CRUD, aggregates)
        $dataPatterns = [
            'how many', 'how much', 'count', 'total', 'number of', 'amount of', 'sum', 'average',
            'create', 'make', 'add', 'new', 'generate', 'update', 'edit', 'modify', 'change',
            'delete', 'remove', 'list', 'show', 'get', 'find', 'search'
        ];
        
        foreach ($dataPatterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return [
                    'type' => 'knowledge_search',
                    'action' => 'search_knowledge',
                    'confidence' => 0.7,
                    'reasoning' => 'Fallback: Detected data operation pattern - routing to AutonomousRAGAgent'
                ];
            }
        }

        // Default: conversational
        return [
            'type' => 'conversational',
            'action' => 'handle_conversational',
            'confidence' => 0.5,
            'reasoning' => 'Fallback: General conversation'
        ];
    }

    /**
     * Analyze for workflow/action request using AI intelligence
     * @deprecated Use analyzeWithUnifiedAI instead
     */
    protected function analyzeForWorkflow(string $message, UnifiedActionContext $context): array
    {
        // Redirect to unified AI analysis
        return $this->analyzeWithUnifiedAI($message, $context);
    }

    /**
     * Check if query is an aggregate query (count, how many, total, etc.)
     * Uses AI for intelligent detection instead of pattern matching
     */
    protected function isAggregateQuery(string $message, UnifiedActionContext $context): bool
    {
        try {
            // Build conversation context for better understanding
            $conversationContext = $this->buildConversationContextString($context);

            $prompt = "";
            if (!empty($conversationContext)) {
                $prompt .= "{$conversationContext}\n";
            }

            $prompt .= "Current message: \"{$message}\"\n\n";
            $prompt .= "Is this an AGGREGATE/STATISTICAL query? (asking for count, total, sum, average, how many, how much, number of, amount of, statistics, etc.)\n";
            $prompt .= "Consider the conversation context if provided.\n";
            $prompt .= "Answer with ONLY 'yes' or 'no':";

            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 3,
                temperature: 0
            );

            $response = $this->aiEngine->generate($request);
            $result = strtolower(trim($response->getContent()));

            $isAggregate = str_contains($result, 'yes');

            Log::channel('ai-engine')->debug('AI aggregate query detection', [
                'message' => $message,
                'is_aggregate' => $isAggregate,
                'ai_response' => $result,
            ]);

            return $isAggregate;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI aggregate detection failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback to pattern matching if AI fails
            return $this->isAggregateQueryFallback($message);
        }
    }

    /**
     * Fallback pattern-based aggregate query detection
     */
    protected function isAggregateQueryFallback(string $message): bool
    {
        $query = strtolower($message);
        $patterns = ['how many', 'how much', 'count', 'total', 'number of', 'amount of', 'sum', 'average', 'statistics'];

        foreach ($patterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract recent conversation context for follow-up detection
     * Returns the last few messages to understand what entity/topic was being discussed
     */
    protected function getRecentConversationContext(UnifiedActionContext $context): array
    {
        $history = $context->conversationHistory ?? [];

        // Get last 4 messages (2 exchanges) for context
        $recentMessages = array_slice($history, -4);

        return [
            'messages' => $recentMessages,
            'last_entity' => $context->get('last_discussed_entity'),
            'last_node' => $context->get('last_remote_node'),
        ];
    }

    /**
     * Build conversation context string for AI prompts
     */
    protected function buildConversationContextString(UnifiedActionContext $context): string
    {
        $recentContext = $this->getRecentConversationContext($context);
        $messages = $recentContext['messages'] ?? [];

        if (empty($messages)) {
            return '';
        }

        $contextStr = "Recent conversation:\n";
        foreach ($messages as $msg) {
            $role = ucfirst($msg['role'] ?? 'unknown');
            $content = $msg['content'] ?? '';
            // Truncate long messages
            if (strlen($content) > 200) {
                $content = substr($content, 0, 200) . '...';
            }
            $contextStr .= "{$role}: {$content}\n";
        }

        return $contextStr;
    }

    /**
     * Detect if this is a follow-up question about a previously discussed entity
     * Uses AI for intelligent detection
     */
    protected function isFollowUpQuestion(string $message, UnifiedActionContext $context): ?array
    {
        $recentContext = $this->getRecentConversationContext($context);
        $lastEntity = $recentContext['last_entity'];
        $lastNode = $recentContext['last_node'];
        $recentMessages = $recentContext['messages'] ?? [];

        // If no previous entity context and no recent messages, not a follow-up
        if (!$lastEntity && !$lastNode && empty($recentMessages)) {
            return null;
        }

        try {
            // Build conversation context for AI
            $conversationContext = $this->buildConversationContextString($context);

            // If no conversation context, can't be a follow-up
            if (empty($conversationContext)) {
                return null;
            }

            $prompt = "{$conversationContext}\n";
            $prompt .= "Current message: \"{$message}\"\n\n";
            $prompt .= "Is this current message a FOLLOW-UP question about something discussed in the recent conversation?\n";
            $prompt .= "A follow-up is when the user asks about the same topic/entity without explicitly naming it again.\n";
            $prompt .= "Examples of follow-ups: 'total amount' after discussing invoices, 'show details' after listing items, 'how much' after mentioning products.\n\n";
            $prompt .= "Answer with ONLY 'yes' or 'no':";

            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 3,
                temperature: 0
            );

            $response = $this->aiEngine->generate($request);
            $result = strtolower(trim($response->getContent()));

            $isFollowUp = str_contains($result, 'yes');

            Log::channel('ai-engine')->info('AI follow-up detection', [
                'message' => $message,
                'is_follow_up' => $isFollowUp,
                'last_entity' => $lastEntity,
                'last_node' => $lastNode,
                'ai_response' => $result,
            ]);

            if ($isFollowUp) {
                return [
                    'is_follow_up' => true,
                    'last_entity' => $lastEntity,
                    'last_node' => $lastNode,
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI follow-up detection failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback to pattern-based detection
            return $this->isFollowUpQuestionFallback($message, $lastEntity, $lastNode);
        }
    }

    /**
     * Fallback pattern-based follow-up detection
     */
    protected function isFollowUpQuestionFallback(string $message, ?string $lastEntity, ?array $lastNode): ?array
    {
        // If no previous entity context, not a follow-up
        if (!$lastEntity && !$lastNode) {
            return null;
        }

        // Check if message is short and lacks entity reference (likely a follow-up)
        $query = strtolower($message);
        $isShortQuery = str_word_count($message) <= 5;
        $hasAggregateKeyword = $this->containsAggregateKeywordFallback($query);

        // If it's a short query with aggregate keywords, likely a follow-up
        if ($isShortQuery && $hasAggregateKeyword) {
            Log::channel('ai-engine')->info('Fallback: Detected potential follow-up question', [
                'message' => $message,
                'last_entity' => $lastEntity,
                'last_node' => $lastNode,
            ]);

            return [
                'is_follow_up' => true,
                'last_entity' => $lastEntity,
                'last_node' => $lastNode,
            ];
        }

        return null;
    }

    /**
     * Fallback pattern-based aggregate keyword detection
     */
    protected function containsAggregateKeywordFallback(string $query): bool
    {
        $patterns = ['how many', 'how much', 'count', 'total', 'number of', 'amount of', 'sum', 'average'];

        foreach ($patterns as $pattern) {
            if (str_contains($query, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Use AI to intelligently detect workflow intent
     * Handles typos, variations, and natural language understanding
     * Now context-aware - includes conversation history for follow-up detection
     */
    protected function detectIntentWithAI(string $message, ?UnifiedActionContext $context = null): ?array
    {
        try {
            // Get available workflow goals to help AI understand what data patterns exist
            $workflowContext = $this->getWorkflowContextForIntent();

            // Build conversation context if available
            $conversationContext = '';
            if ($context) {
                $conversationContext = $this->buildConversationContextString($context);
            }

            $prompt = "";

            // Include conversation context if available
            if (!empty($conversationContext)) {
                $prompt .= "{$conversationContext}\n";
            }

            $prompt .= "Current user message: \"{$message}\"\n\n";
            $prompt .= "What does the user want to DO? Consider the conversation context if provided. Respond with ONE word:\n\n";
            $prompt .= "create - if they want to CREATE/MAKE/ADD/BUILD/GENERATE/SETUP/REGISTER something NEW\n";
            $prompt .= "update - if they want to UPDATE/EDIT/MODIFY/CHANGE existing data\n";
            $prompt .= "delete - if they want to DELETE/REMOVE something\n";
            $prompt .= "read - if they want to VIEW/SHOW/LIST/FIND/SEARCH/GET/FILTER information (includes date filters like 'invoices at 26-01-2026')\n";
            $prompt .= "followup - if this is a FOLLOW-UP question about something previously discussed (e.g., 'total amount' after discussing invoices)\n";
            $prompt .= "suggest - if the message contains MULTIPLE items with quantities/prices that look like a transaction (e.g., '2 laptops at $500 each')\n";
            $prompt .= "none - if they're just CHATTING/GREETING/ASKING ABOUT CAPABILITIES\n\n";
            $prompt .= "IMPORTANT: A query with a date filter (e.g., 'invoices at 26-01-2026', 'bills from January') is READ, not suggest!\n";
            $prompt .= "IMPORTANT: If the user asks about 'total', 'amount', 'sum' etc. and there's recent conversation about a specific entity, it's a FOLLOWUP!\n\n";

            if (!empty($workflowContext)) {
                $prompt .= "Available workflows can process:\n{$workflowContext}\n";
                $prompt .= "If message contains data matching any workflow but no explicit command, respond 'suggest'.\n\n";
            }

            $prompt .= "Ignore typos. Focus on intent.\n";
            $prompt .= "Answer (create/update/delete/read/followup/suggest/none):";

            $request = new AIRequest(
                prompt: $prompt,
                maxTokens: 5,
                temperature: 0
            );

            $response = $this->aiEngine->generate($request);
            $intent = strtolower(trim($response->getContent()));

            // Check if it's a CRUD operation
            if (in_array($intent, ['create', 'update', 'delete'])) {
                Log::channel('ai-engine')->info('AI detected workflow intent', [
                    'message' => $message,
                    'detected_intent' => $intent,
                ]);

                return [
                    'type' => 'new_workflow',
                    'action' => 'start_workflow',
                    'operation' => $intent,
                    'crud_operation' => $intent,
                    'confidence' => 0.85,
                    'reasoning' => "AI detected {$intent} operation"
                ];
            }

            // READ intent should use RAG/knowledge search
            if ($intent === 'read') {
                Log::channel('ai-engine')->info('AI detected read/search intent - using RAG', [
                    'message' => $message,
                ]);

                return [
                    'type' => 'knowledge_search',
                    'action' => 'search_knowledge',
                    'confidence' => 0.85,
                    'reasoning' => 'AI detected read/search/query intent - using RAG'
                ];
            }

            // FOLLOWUP intent - route to last discussed entity/node
            if ($intent === 'followup' && $context) {
                $recentContext = $this->getRecentConversationContext($context);
                $lastEntity = $recentContext['last_entity'];
                $lastNode = $recentContext['last_node'];

                Log::channel('ai-engine')->info('AI detected follow-up question', [
                    'message' => $message,
                    'last_entity' => $lastEntity,
                    'last_node' => $lastNode,
                ]);

                // If we have a last remote node, route back to it
                if ($lastNode && isset($lastNode['node_id'])) {
                    return [
                        'type' => 'remote_routing',
                        'action' => 'route_to_remote_node',
                        'confidence' => 0.9,
                        'reasoning' => 'AI detected follow-up question about ' . ($lastEntity ?? 'previous topic'),
                        'target_node' => $lastNode,
                        'collector_name' => $lastEntity,
                        'is_follow_up' => true,
                        'conversation_context' => $this->buildConversationContextString($context),
                    ];
                }

                // If we have a last entity but no remote node, use RAG with context
                return [
                    'type' => 'knowledge_search',
                    'action' => 'search_knowledge',
                    'confidence' => 0.88,
                    'reasoning' => 'AI detected follow-up question - using RAG with context',
                    'context_entity' => $lastEntity,
                    'is_follow_up' => true,
                    'conversation_context' => $this->buildConversationContextString($context),
                ];
            }

            // Check if AI detected transaction data that could be an invoice/bill
            if ($intent === 'suggest') {
                Log::channel('ai-engine')->info('AI detected transaction data - suggesting workflow', [
                    'message' => $message,
                ]);

                return [
                    'type' => 'suggestion',
                    'action' => 'suggest_workflow',
                    'confidence' => 0.8,
                    'reasoning' => 'Message contains transaction data - suggesting invoice/bill creation',
                    'suggested_workflows' => $this->detectSuggestedWorkflows($message),
                ];
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI intent detection failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Detect which workflows to suggest based on message content using AI
     * Generic - works with any discoverable workflow
     */
    protected function detectSuggestedWorkflows(string $message): array
    {
        // Get available workflows
        $workflows = [];
        if ($this->workflowDiscovery) {
            $discovered = $this->workflowDiscovery->discoverWorkflows(useCache: true);
            foreach ($discovered as $class => $metadata) {
                $workflows[] = [
                    'class' => $class,
                    'goal' => $metadata['goal'] ?? class_basename($class),
                    'triggers' => $metadata['triggers'] ?? [],
                ];
            }
        }

        if (empty($workflows)) {
            Log::channel('ai-engine')->warning('No workflows available for suggestion');
            return [];
        }

        // Use AI to determine which workflow(s) best match the data in the message
        try {
            $prompt = "User message: \"{$message}\"\n\n";
            $prompt .= "Available workflows:\n";

            foreach ($workflows as $index => $workflow) {
                $num = $index + 1;
                $prompt .= "{$num}. {$workflow['goal']}\n";
            }

            $prompt .= "\nBased on the data in the message, which workflow(s) could process this?\n";
            $prompt .= "Consider what the message contains and match to the most appropriate workflow goal.\n";
            $prompt .= "Respond with ONLY the number(s) separated by comma, or '0' if none match.\n";

            $response = $this->aiEngine->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 10,
                temperature: 0
            ));

            $result = trim($response->getContent());
            $selectedNumbers = array_map('intval', explode(',', $result));

            $suggestions = [];
            foreach ($selectedNumbers as $num) {
                if ($num > 0 && isset($workflows[$num - 1])) {
                    $workflow = $workflows[$num - 1];
                    $workflowName = $this->extractWorkflowName($workflow['class']);

                    $suggestions[] = [
                        'workflow' => $workflowName,
                        'workflow_class' => $workflow['class'],
                        'label' => 'Create ' . ucfirst($workflowName),
                        'description' => $workflow['goal'],
                    ];
                }
            }

            if (!empty($suggestions)) {
                Log::channel('ai-engine')->info('AI suggested workflows', [
                    'message' => substr($message, 0, 50),
                    'suggestions' => array_column($suggestions, 'workflow'),
                ]);
                return $suggestions;
            }

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI workflow suggestion failed', [
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: return first workflow if any exist
        if (!empty($workflows)) {
            $workflow = $workflows[0];
            $workflowName = $this->extractWorkflowName($workflow['class']);
            return [[
                'workflow' => $workflowName,
                'workflow_class' => $workflow['class'],
                'label' => 'Create ' . ucfirst($workflowName),
                'description' => $workflow['goal'],
            ]];
        }

        return [];
    }

    /**
     * Extract workflow name from class name
     */
    protected function extractWorkflowName(string $class): string
    {
        $basename = class_basename($class);
        // Remove common suffixes
        $name = preg_replace('/(Declarative|Create|Workflow)/', '', $basename);
        return strtolower(trim($name)) ?: 'document';
    }

    /**
     * Get workflow context for intent detection
     * Returns a summary of what data patterns available workflows can process
     */
    protected function getWorkflowContextForIntent(): string
    {
        if (!$this->workflowDiscovery) {
            return '';
        }

        try {
            $discovered = $this->workflowDiscovery->discoverWorkflows(useCache: true);

            if (empty($discovered)) {
                return '';
            }

            $context = [];
            foreach ($discovered as $class => $metadata) {
                $goal = $metadata['goal'] ?? '';
                if (!empty($goal)) {
                    $context[] = "- {$goal}";
                }
            }

            return implode("\n", $context);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Check if remote nodes can handle this request based on their capabilities
     * Reads directly from AINode model - no API calls needed
     * Detects BOTH create operations (autonomous collectors) AND query operations (list, show, search)
     */
    protected function checkRemoteNodeCapabilities(string $message, UnifiedActionContext $context): ?array
    {
        try {
            // IMPORTANT: For short/ambiguous queries with conversation context,
            // defer to AutonomousRAGAgent which has better context-aware routing
            // This prevents routing "total amount" to wrong node after discussing invoices
            $conversationContext = $this->buildConversationContextString($context);
            $isShortQuery = str_word_count($message) <= 4;

            if ($isShortQuery && !empty($conversationContext)) {
                Log::channel('ai-engine')->debug('Short query with conversation context - deferring to AutonomousRAGAgent', [
                    'message' => $message,
                    'word_count' => str_word_count($message),
                ]);
                // Return null to let analyzeWithUnifiedAI or KnowledgeSearchHandler handle it
                // They use AutonomousRAGAgent which has conversation-aware routing
                return null;
            }

            $registry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
            $nodes = $registry->getActiveNodes();

            if ($nodes->isEmpty()) {
                return null;
            }

            $capabilitiesContext = [];
            $nodesByEntity = [];

            foreach ($nodes as $node) {
                $nodeCollectors = $node->autonomous_collectors ?? [];

                if (is_array($nodeCollectors)) {
                    foreach ($nodeCollectors as $collectorData) {
                        if (!is_array($collectorData)) {
                            continue;
                        }

                        $collectorName = $collectorData['name'] ?? null;
                        if (!$collectorName) {
                            continue;
                        }

                        $description = $collectorData['goal'] ?? $collectorData['description'] ?? 'Autonomous collector';
                        $this->registerRemoteCapability(
                            $capabilitiesContext,
                            $nodesByEntity,
                            $node,
                            $collectorName,
                            $description,
                            'collector'
                        );
                    }
                }

                $dataTypes = $node->data_types ?? [];
                if (is_array($dataTypes)) {
                    foreach ($dataTypes as $type) {
                        if (!is_string($type) || $type === '') {
                            continue;
                        }
                        $label = str_replace('_', ' ', $type);
                        $description = "Handles {$label} data and queries";
                        $this->registerRemoteCapability(
                            $capabilitiesContext,
                            $nodesByEntity,
                            $node,
                            $label,
                            $description,
                            'data_type'
                        );
                    }
                }

                $keywords = $node->keywords ?? [];
                if (is_array($keywords)) {
                    foreach ($keywords as $keyword) {
                        if (!is_string($keyword) || strlen($keyword) < 3) {
                            continue;
                        }
                        $description = 'Relevant keyword from node metadata';
                        $this->registerRemoteCapability(
                            $capabilitiesContext,
                            $nodesByEntity,
                            $node,
                            $keyword,
                            $description,
                            'keyword'
                        );
                    }
                }

                $workflows = $node->workflows ?? [];
                if (is_array($workflows)) {
                    foreach ($workflows as $workflowClass) {
                        if (!is_string($workflowClass)) {
                            continue;
                        }
                        $entity = $this->extractEntityFromWorkflowClass($workflowClass);
                        if ($entity) {
                            $description = 'Workflow available: ' . class_basename($workflowClass);
                            $this->registerRemoteCapability(
                                $capabilitiesContext,
                                $nodesByEntity,
                                $node,
                                $entity,
                                $description,
                                'workflow'
                            );
                        }
                    }
                }
            }

            if (empty($capabilitiesContext)) {
                return null;
            }

            $match = $this->detectRemoteNodeMatch($message, $capabilitiesContext, $nodesByEntity);

            if ($match) {
                $nodeData = $nodesByEntity[$match] ?? null;

                if ($nodeData) {
                    Log::channel('ai-engine')->info('Remote node capability detected during analysis', [
                        'entity' => $nodeData['label'] ?? $match,
                        'node' => $nodeData['node_name'],
                        'source' => $nodeData['source'] ?? 'metadata',
                    ]);

                    return [
                        'type' => 'remote_routing',
                        'action' => 'route_to_remote_node',
                        'confidence' => 0.95,
                        'reasoning' => 'Remote node can handle this request',
                        'target_node' => [
                            'node_id' => $nodeData['node_id'],
                            'node_slug' => $nodeData['node_slug'],
                            'node_name' => $nodeData['node_name'],
                            'capability_source' => $nodeData['source'] ?? null,
                        ],
                        'collector_name' => $capabilitiesContext[$match]['label'] ?? $match,
                    ];
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to check remote node capabilities', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Use AI to detect if message should be routed to a remote node
     * Handles both create operations AND query operations (list, show, search, etc.)
     */
    protected function detectRemoteNodeMatch(string $message, array $capabilities, array $entityCatalog): ?string
    {
        if (empty($capabilities) && empty($entityCatalog)) {
            return null;
        }

        // Build context showing what each entity type can do
        $entitiesText = '';
        foreach ($capabilities as $key => $data) {
            $label = $data['label'] ?? $key;
            $goal = $data['goal'] ?? $data['description'] ?? '';
            $node = $data['node_name'] ?? 'Unknown';
            $source = $data['source'] ?? 'metadata';
            $entitiesText .= "- {$label}: {$goal} (node {$node}, via {$source})\n";
        }

        $prompt = "Given this user message: \"{$message}\"\n\n";
        $prompt .= "Available remote data types:\n{$entitiesText}\n";
        $prompt .= "Does the message relate to ANY of these data types (create, list, show, search, update, delete, etc.)?\n";
        $prompt .= "Reply with ONLY the data type name if yes, or 'none' if no match.";

        try {
            $response = $this->aiEngine->generateText(
                new AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    temperature: 0.1,
                    maxTokens: 50
                )
            );

            $result = strtolower(trim($response->getContent()));
            $result = preg_replace('/[^a-z0-9_\\s]/', '', $result);

            if ($result !== 'none') {
                foreach ($entityCatalog as $key => $metadata) {
                    $aliases = $metadata['aliases'] ?? [];
                    $aliases[] = $metadata['label'] ?? $key;
                    $aliases[] = $key;
                    $aliases = array_filter(array_unique(array_map(fn($alias) => strtolower($alias), $aliases)));

                    foreach ($aliases as $alias) {
                        if ($alias && ($result === $alias || str_contains($result, $alias))) {
                            return $key;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('AI detection failed for remote node match', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    protected function registerRemoteCapability(
        array &$capabilitiesContext,
        array &$nodesByEntity,
        $node,
        string $entityName,
        string $description,
        string $source
    ): void {
        $normalized = $this->normalizeEntityKey($entityName);
        if ($normalized === '') {
            return;
        }

        $priority = $this->getCapabilityPriority($source);
        if (isset($capabilitiesContext[$normalized]) && ($capabilitiesContext[$normalized]['priority'] ?? 0) >= $priority) {
            return;
        }

        $label = trim($entityName);
        $aliases = array_filter(array_unique([
            $label,
            Str::singular($label),
            Str::plural($label),
            str_replace('_', ' ', $normalized),
        ]));

        $capabilitiesContext[$normalized] = [
            'label' => $label,
            'goal' => $description,
            'description' => $description,
            'node_name' => $node->name,
            'node_id' => $node->id,
            'node_slug' => $node->slug,
            'source' => $source,
            'priority' => $priority,
            'aliases' => $aliases,
        ];

        $nodesByEntity[$normalized] = [
            'node_id' => $node->id,
            'node_slug' => $node->slug,
            'node_name' => $node->name,
            'source' => $source,
            'label' => $label,
            'aliases' => $aliases,
        ];
    }

    protected function normalizeEntityKey(string $name): string
    {
        $normalized = trim(strtolower($name));
        if ($normalized === '') {
            return '';
        }
        $normalized = str_replace(['-', '/'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        return str_replace(' ', '_', $normalized);
    }

    protected function getCapabilityPriority(string $source): int
    {
        return match ($source) {
            'collector' => 4,
            'workflow' => 3,
            'data_type' => 2,
            'keyword' => 1,
            default => 0,
        };
    }

    protected function extractEntityFromWorkflowClass(string $workflowClass): ?string
    {
        $basename = class_basename($workflowClass);
        $entity = preg_replace('/(Declarative|Workflow|Create|Update|Delete|Manage)/i', '', $basename);
        $entity = trim($entity);
        if ($entity === '') {
            return null;
        }

        $entity = Str::snake($entity, ' ');
        return str_replace('_', ' ', $entity);
    }
}
