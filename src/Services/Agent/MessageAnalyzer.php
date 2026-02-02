<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\IntentAnalysisService;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\WorkflowDiscoveryService;
use Illuminate\Support\Facades\Log;

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

        // PRIORITY 3: Check if remote nodes can handle this request
        if (config('ai-engine.nodes.enabled', false)) {
            $remoteRouting = $this->checkRemoteNodeCapabilities($message, $context);
            if ($remoteRouting) {
                return $remoteRouting;
            }
        }

        // PRIORITY 4: Use AI to intelligently route the message
        return $this->analyzeForWorkflow($message, $context);
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
     * Analyze for workflow/action request using AI intelligence
     */
    protected function analyzeForWorkflow(string $message, UnifiedActionContext $context): array
    {
        // PRIORITY: Check if there's pending suggestion data and user is confirming
        $suggestedData = $context->get('suggested_transaction_data');
        if ($suggestedData && $this->intentAnalysis->isConfirmation($message)) {
            Log::channel('ai-engine')->info('User confirmed suggestion - starting workflow', [
                'message' => $message,
            ]);
            return [
                'type' => 'new_workflow',
                'action' => 'start_workflow',
                'operation' => 'create',
                'crud_operation' => 'create',
                'confidence' => 0.95,
                'reasoning' => 'User confirmed suggestion to create document'
            ];
        }
        
        // Quick check: aggregate queries (how many, count, total) should use RAG
        if ($this->isAggregateQuery($message)) {
            Log::channel('ai-engine')->info('Detected aggregate query - using RAG', [
                'message' => $message,
            ]);
            return [
                'type' => 'knowledge_search',
                'action' => 'search_knowledge',
                'confidence' => 0.9,
                'reasoning' => 'Aggregate query (how many/count/total) - using RAG'
            ];
        }
        
        // Use AI to intelligently detect intent (handles typos, variations, and natural language)
        $aiIntent = $this->detectIntentWithAI($message);
        if ($aiIntent) {
            return $aiIntent;
        }

        // Default: conversational
        return [
            'type' => 'conversational',
            'action' => 'handle_conversational',
            'confidence' => 0.6,
            'reasoning' => 'General conversation'
        ];
    }
    
    /**
     * Check if query is an aggregate query (count, how many, total, etc.)
     */
    protected function isAggregateQuery(string $message): bool
    {
        $query = strtolower($message);
        $patterns = ['how many', 'how much', 'count', 'total', 'number of', 'amount of'];
        
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
     */
    protected function detectIntentWithAI(string $message): ?array
    {
        try {
            // Get available workflow goals to help AI understand what data patterns exist
            $workflowContext = $this->getWorkflowContextForIntent();
            
            $prompt = "User message: \"{$message}\"\n\n";
            $prompt .= "What does the user want to DO? Respond with ONE word:\n\n";
            $prompt .= "create - if they want to CREATE/MAKE/ADD/BUILD/GENERATE/SETUP/REGISTER something NEW\n";
            $prompt .= "update - if they want to UPDATE/EDIT/MODIFY/CHANGE existing data\n";
            $prompt .= "delete - if they want to DELETE/REMOVE something\n";
            $prompt .= "read - if they want to VIEW/SHOW/LIST/FIND/SEARCH/GET/FILTER information (includes date filters like 'invoices at 26-01-2026')\n";
            $prompt .= "suggest - if the message contains MULTIPLE items with quantities/prices that look like a transaction (e.g., '2 laptops at $500 each')\n";
            $prompt .= "none - if they're just CHATTING/GREETING/ASKING ABOUT CAPABILITIES\n\n";
            $prompt .= "IMPORTANT: A query with a date filter (e.g., 'invoices at 26-01-2026', 'bills from January') is READ, not suggest!\n\n";
            
            if (!empty($workflowContext)) {
                $prompt .= "Available workflows can process:\n{$workflowContext}\n";
                $prompt .= "If message contains data matching any workflow but no explicit command, respond 'suggest'.\n\n";
            }
            
            $prompt .= "Ignore typos. Focus on intent.\n";
            $prompt .= "Answer (create/update/delete/read/suggest/none):";

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
            // Get active nodes with their autonomous_collectors from database
            $registry = app(\LaravelAIEngine\Services\Node\NodeRegistryService::class);
            $nodes = $registry->getActiveNodes();
            
            if ($nodes->isEmpty()) {
                return null;
            }

            // Build capabilities context from node data
            // Include both collectors (for create) and entity types (for queries)
            $capabilitiesContext = [];
            $nodesByEntity = [];
            
            foreach ($nodes as $node) {
                $nodeCollectors = $node->autonomous_collectors ?? [];
                
                if (!is_array($nodeCollectors) || empty($nodeCollectors)) {
                    continue;
                }
                
                foreach ($nodeCollectors as $collectorData) {
                    if (!is_array($collectorData)) {
                        continue;
                    }
                    
                    $collectorName = $collectorData['name'] ?? null;
                    if (!$collectorName) {
                        continue;
                    }
                    
                    // Store for create operations
                    $capabilitiesContext[$collectorName] = [
                        'goal' => $collectorData['goal'] ?? $collectorData['description'] ?? '',
                        'description' => $collectorData['description'] ?? $collectorData['goal'] ?? '',
                        'node_name' => $node->name,
                        'node_id' => $node->id,
                        'node_slug' => $node->slug,
                    ];
                    
                    // Track which node handles which entity type (for queries)
                    $nodesByEntity[$collectorName] = [
                        'node_id' => $node->id,
                        'node_slug' => $node->slug,
                        'node_name' => $node->name,
                    ];
                }
            }
            
            if (empty($capabilitiesContext)) {
                return null;
            }

            // Use AI to determine if message should be routed to a remote node
            $match = $this->detectRemoteNodeMatch($message, $capabilitiesContext, array_keys($nodesByEntity));
            
            if ($match) {
                $nodeData = $nodesByEntity[$match] ?? $capabilitiesContext[$match] ?? null;
                
                if ($nodeData) {
                    Log::channel('ai-engine')->info('Remote node capability detected during analysis', [
                        'entity' => $match,
                        'node' => $nodeData['node_name'],
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
                        ],
                        'collector_name' => $match,
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
    protected function detectRemoteNodeMatch(string $message, array $capabilities, array $entityTypes): ?string
    {
        if (empty($capabilities) && empty($entityTypes)) {
            return null;
        }

        // Build context showing what each entity type can do
        $entitiesText = '';
        foreach ($capabilities as $name => $data) {
            $goal = $data['goal'] ?? $data['description'] ?? '';
            $node = $data['node_name'] ?? 'Unknown';
            $entitiesText .= "- {$name}: Can create, list, show, search, update {$name}s (on {$node})\n";
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
            
            // Check if result matches any entity type
            if ($result !== 'none') {
                foreach ($entityTypes as $entity) {
                    if ($result === strtolower($entity) || str_contains($result, strtolower($entity))) {
                        return $entity;
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
}
