<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler;
use LaravelAIEngine\Services\RAG\AutonomousRAGAgent;
use Illuminate\Support\Facades\Log;

/**
 * Minimal AI-Driven Orchestrator
 *
 * Reduces hardcoded logic by 75% - AI handles all routing decisions.
 *
 * Only 2 hardcoded rules:
 * 1. If active session exists → continue it
 * 2. Otherwise → ask AI everything
 */
class MinimalAIOrchestrator
{
    public function __construct(
        protected AIEngineService $ai,
        protected ContextManager $contextManager,
        protected AutonomousCollectorRegistry $collectorRegistry,
        protected NodeRegistryService $nodeRegistry
    ) {}

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('MinimalAIOrchestrator processing', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message' => substr($message, 0, 100),
        ]);

        $context = $this->contextManager->getOrCreate($sessionId, $userId);
        $context->addUserMessage($message);

        // RULE 1: Active session? Continue it (no AI needed)
        if ($context->has('autonomous_collector')) {
            Log::channel('ai-engine')->info('Continuing active collector session');
            return $this->continueCollector($message, $context, $options);
        }

        if ($context->has('routed_to_node')) {
            Log::channel('ai-engine')->info('Continuing routed node session');
            return $this->continueNode($message, $context, $options);
        }

        // RULE 2a: Skip AI decision if flagged (from RAG exit_to_orchestrator)
        // Try to match collectors directly to avoid infinite loop
        if (!empty($options['skip_ai_decision'])) {
            Log::channel('ai-engine')->info('Skipping AI decision, trying direct collector match');

            // Try to find matching collector
            $match = $this->collectorRegistry->findConfigForMessage($message);
            if ($match) {
                Log::channel('ai-engine')->info('Found matching collector', ['name' => $match['name']]);
                $handler = app(AutonomousCollectorHandler::class);
                return $handler->handle($message, $context, array_merge($options, [
                    'action' => 'start_autonomous_collector',
                    'collector_match' => $match,
                ]));
            }

            // No collector found - return helpful error
            Log::channel('ai-engine')->warning('No collector found for message after RAG exit', [
                'message' => substr($message, 0, 100),
            ]);

            // Provide helpful feedback based on the message intent
            $messageLower = strtolower($message);
            if (preg_match('/\b(delete|remove|cancel)\b/i', $message)) {
                $errorMessage = "Delete operations are not currently available through the AI assistant. Please use the application interface to delete records.";
            } else {
                $errorMessage = "I couldn't find a way to handle that request. I can help you create, update, or search for records. What would you like to do?";
            }

            return AgentResponse::conversational(
                message: $errorMessage,
                context: $context
            );
        }

        // RULE 2: No active session? Ask AI everything
        return $this->askAI($message, $context, $options);
    }

    protected function continueCollector(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $handler = app(AutonomousCollectorHandler::class);

        $response = $handler->handle($message, $context, array_merge($options, [
            'action' => 'continue_autonomous_collector',
        ]));

        $context->addAssistantMessage($response->message);
        $this->contextManager->save($context);

        return $response;
    }

    protected function continueNode(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $nodeInfo = $context->get('routed_to_node');
        $nodeSlug = $nodeInfo['node_slug'] ?? null;

        if (!$nodeSlug) {
            $context->forget('routed_to_node');
            return $this->askAI($message, $context, $options);
        }

        $node = $this->nodeRegistry->getNode($nodeSlug);

        if (!$node) {
            $context->forget('routed_to_node');
            return $this->askAI($message, $context, $options);
        }
        // Extract user token and forwardable headers for authentication
        $userToken = request()->bearerToken();
        $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

        // Merge authentication headers
        $headers = array_merge($forwardHeaders, [
            'X-Forwarded-From-Node' => config('app.name'),
            'X-User-Token' => $userToken,
        ]);

        // Add headers to options for forwarding
        $options['headers'] = $headers;
        $options['user_token'] = $userToken;


        $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);
        $response = $router->forwardChat($node, $message, $context->sessionId, $options, $context->userId);

        if ($response['success']) {
            $agentResponse = AgentResponse::success(
                message: $response['response'],
                context: $context,
                data: $response
            );

            $context->addAssistantMessage($response['response']);
            $this->contextManager->save($context);

            return $agentResponse;
        }

        $context->forget('routed_to_node');
        return $this->askAI($message, $context, $options);
    }

    protected function askAI(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $resources = $this->discoverResources($options);

        Log::channel('ai-engine')->info('MinimalAIOrchestrator resources discovered', [
            'collectors_count' => count($resources['collectors']),
            'collectors' => array_map(fn($c) => $c['name'], $resources['collectors']),
            'tools_count' => count($resources['tools']),
            'nodes_count' => count($resources['nodes']),
        ]);

        // Build prompt with resources
        $prompt = $this->buildPrompt($message, $resources, $context);

        // Log the full prompt for debugging
        Log::channel('ai-engine')->debug('AI Orchestration Prompt', [
            'prompt' => $prompt,
            'message' => $message,
            'session_id' => $context->sessionId,
        ]);

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from(config('ai-engine.default', 'openai')),
                model: EntityEnum::from(config('ai-engine.orchestration_model', 'gpt-4o-mini')),
                maxTokens: 300,
                temperature: 0.1
            );

            $aiResponse = $this->ai->generate($request);
            $rawResponse = $aiResponse->getContent();

            // Log raw AI response for debugging
            Log::channel('ai-engine')->debug('AI Raw Response', [
                'raw_response' => $rawResponse,
                'session_id' => $context->sessionId,
            ]);

            $decision = $this->parseDecision($rawResponse, $resources);

            Log::channel('ai-engine')->info('AI orchestration decision', [
                'message' => substr($message, 0, 100),
                'action' => $decision['action'],
                'resource' => $decision['resource_name'],
                'reason' => substr($decision['reason'] ?? '', 0, 100),
            ]);

            $response = $this->execute($decision, $message, $context, $options);

            $context->addAssistantMessage($response->message);
            $this->contextManager->save($context);

            return $response;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI orchestration failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->executeSearchRAG($message, $context, $options);
        }
    }

    protected function discoverResources(array $options): array
    {
        return [
            'tools' => $this->discoverTools($options),
            'collectors' => $this->discoverCollectors(),
            'nodes' => $this->discoverNodes(),
        ];
    }

    protected function discoverTools(array $options): array
    {
        $tools = [];
        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();

        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                continue;
            }

            try {
                $configTools = $configClass::getTools();
                $modelName = method_exists($configClass, 'getName')
                    ? $configClass::getName()
                    : class_basename($configClass);

                foreach ($configTools as $toolName => $toolDef) {
                    $tools[] = [
                        'name' => $toolName,
                        'model' => $modelName,
                        'description' => $toolDef['description'] ?? '',
                    ];
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->debug('Failed to get tools from config', [
                    'config' => $configClass,
                ]);
            }
        }

        return $tools;
    }

    protected function discoverCollectors(): array
    {
        $collectors = [];

        // 1. Discover local collectors from static registry
        try {
            $localCollectors = \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::getConfigs();

            foreach ($localCollectors as $name => $configData) {
                $collectors[] = [
                    'name' => $name,
                    'goal' => $configData['goal'] ?? '',
                    'description' => $configData['description'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover local collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Discover collectors from remote nodes
        try {
            $activeNodes = $this->nodeRegistry->getActiveNodes();

            foreach ($activeNodes as $node) {
                $autonomousCollectors = $node['autonomous_collectors'] ?? [];

                if (is_array($autonomousCollectors)) {
                    foreach ($autonomousCollectors as $collector) {
                        if (isset($collector['name'])) {
                            // Add remote collector with node info
                            $collectors[] = [
                                'name' => $collector['name'],
                                'goal' => $collector['goal'] ?? '',
                                'description' => $collector['description'] ?? '',
                                'node' => $node['slug'] ?? 'unknown',
                            ];
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to discover remote collectors', [
                'error' => $e->getMessage(),
            ]);
        }

        return $collectors;
    }

    protected function discoverNodes(): array
    {
        $nodes = [];
        $activeNodes = $this->nodeRegistry->getActiveNodes();

        foreach ($activeNodes as $node) {
            // Handle both object and array access
            $slug = is_array($node) ? ($node['slug'] ?? '') : $node->slug;
            $name = is_array($node) ? ($node['name'] ?? '') : $node->name;
            $description = is_array($node) ? ($node['description'] ?? '') : ($node->description ?? '');
            $domains = is_array($node) ? ($node['domains'] ?? []) : ($node->domains ?? []);

            $nodes[] = [
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'domains' => $domains,
            ];
        }

        return $nodes;
    }

    protected function discoverModelConfigs(): array
    {
        $configs = [];
        $configPath = app_path('AI/Configs');

        if (!is_dir($configPath)) {
            return [];
        }

        $files = glob($configPath . '/*ModelConfig.php');

        foreach ($files as $file) {
            $className = 'App\\AI\\Configs\\' . basename($file, '.php');
            if (class_exists($className)) {
                $configs[] = $className;
            }
        }

        return $configs;
    }

    protected function buildPrompt(
        string $message,
        array $resources,
        UnifiedActionContext $context
    ): string {
        $history = $this->formatHistory($context);
        $pausedSessions = $context->get('session_stack', []);

        return <<<PROMPT
You are an AI orchestrator. Decide what to do with this message.

CONVERSATION HISTORY:
{$history}

PAUSED SESSIONS: {$this->formatPausedSessions($pausedSessions)}

AVAILABLE RESOURCES:

**Autonomous Collectors** (AI-guided multi-turn data collection):
{$this->formatCollectors($resources['collectors'])}

**Model Tools** (Direct operations):
{$this->formatTools($resources['tools'])}

**Remote Nodes** (Specialized services):
{$this->formatNodes($resources['nodes'])}

USER: "{$message}"

Analyze the conversation history and user's message. If the conversation context shows the user is working with a specific entity or the assistant's last response came from a remote node, continue routing to that same node for follow-up questions.

Choose the most appropriate action:
- start_collector: When user wants to create, update, or delete data
- search_rag: When user wants to view, list, search, or get information
- conversational: For greetings and general chat
- route_to_node: When continuing a conversation about a specific domain handled by a remote node
- resume_session: When user says "back" or "resume"

Match the user's intent to available collectors based on their goals.

RESPOND WITH EXACTLY THIS FORMAT:
ACTION: <start_collector|use_tool|route_to_node|resume_session|pause_and_handle|search_rag|conversational>
RESOURCE: <name or "none">
REASON: <why>
PROMPT;
    }

    protected function formatHistory(UnifiedActionContext $context): string
    {
        $messages = $context->conversationHistory;

        if (empty($messages) || count($messages) <= 1) {
            return "(New conversation)";
        }

        // Show last 5 messages with more content for better context
        $recent = array_slice($messages, -5);
        $lines = [];

        foreach ($recent as $msg) {
            $role = ucfirst($msg['role']);
            $content = substr($msg['content'], 0, 300); // Increased from 100 to 300 chars
            $lines[] = "   {$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    protected function formatPausedSessions(array $sessions): string
    {
        if (empty($sessions)) {
            return "None";
        }

        return implode(', ', array_map(fn($s) => $s['config_name'] ?? 'unknown', $sessions));
    }

    protected function formatCollectors(array $collectors): string
    {
        if (empty($collectors)) {
            return "   (No collectors available)";
        }

        $lines = [];
        foreach ($collectors as $collector) {
            $lines[] = "   - {$collector['name']}: {$collector['goal']}";
        }

        return implode("\n", $lines);
    }

    protected function formatTools(array $tools): string
    {
        if (empty($tools)) {
            return "   (No tools available)";
        }

        $lines = [];
        foreach ($tools as $tool) {
            $lines[] = "   - {$tool['name']} ({$tool['model']}): {$tool['description']}";
        }

        return implode("\n", $lines);
    }

    protected function formatNodes(array $nodes): string
    {
        if (empty($nodes)) {
            return "   (No remote nodes available)";
        }

        $lines = [];
        foreach ($nodes as $node) {
            $domains = implode(', ', $node['domains']);
            $lines[] = "   - {$node['slug']}: {$node['description']} [Domains: {$domains}]";
        }

        return implode("\n", $lines);
    }

    protected function parseDecision(string $response, array $resources): array
    {
        $decision = [
            'action' => 'conversational',
            'resource_name' => null,
            'reasoning' => 'Default fallback',
        ];

        if (preg_match('/ACTION:\s*(\w+)/i', $response, $matches)) {
            $decision['action'] = strtolower(trim($matches[1]));
        }

        if (preg_match('/RESOURCE:\s*(.+?)(?:\n|$)/i', $response, $matches)) {
            $resourceName = trim($matches[1]);
            if ($resourceName !== 'none' && $resourceName !== 'null') {
                $decision['resource_name'] = $resourceName;
            }
        }

        if (preg_match('/REASON:\s*(.+)/i', $response, $matches)) {
            $decision['reasoning'] = trim($matches[1]);
        }

        return $decision;
    }

    protected function execute(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $action = $decision['action'];

        switch ($action) {
            case 'start_collector':
                return $this->executeStartCollector($decision, $message, $context, $options);

            case 'use_tool':
                return $this->executeUseTool($decision, $message, $context, $options);

            case 'resume_session':
                return $this->executeResumeSession($message, $context, $options);

            case 'pause_and_handle':
                return $this->executePauseAndHandle($message, $context, $options);

            case 'route_to_node':
                return $this->executeRouteToNode($decision, $message, $context, $options);

            case 'search_rag':
                return $this->executeSearchRAG($message, $context, $options);

            case 'conversational':
                return $this->executeConversational($message, $context, $options);

            default:
                // Unknown action - fallback to RAG search
                return $this->executeSearchRAG($message, $context, $options);
        }
    }

    protected function executeUseTool(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $toolName = $decision['resource_name'];
        
        Log::channel('ai-engine')->info('MinimalAIOrchestrator: executeUseTool called', [
            'tool_name' => $toolName,
            'message' => $message,
            'decision' => $decision,
        ]);
        
        if (!$toolName) {
            Log::channel('ai-engine')->error('MinimalAIOrchestrator: No tool name provided');
            return AgentResponse::failure(
                message: 'No tool specified',
                context: $context
            );
        }
        
        // Find the tool in model configs
        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();
        
        Log::channel('ai-engine')->info('MinimalAIOrchestrator: Searching for tool in configs', [
            'tool_name' => $toolName,
            'config_count' => count($modelConfigs),
            'configs' => array_map('class_basename', $modelConfigs),
        ]);
        
        foreach ($modelConfigs as $configClass) {
            if (!method_exists($configClass, 'getTools')) {
                Log::channel('ai-engine')->debug('MinimalAIOrchestrator: Config has no getTools method', [
                    'config' => class_basename($configClass),
                ]);
                continue;
            }
            
            try {
                $tools = $configClass::getTools();
                
                Log::channel('ai-engine')->debug('MinimalAIOrchestrator: Checking config tools', [
                    'config' => class_basename($configClass),
                    'tool_names' => array_keys($tools),
                    'looking_for' => $toolName,
                ]);
                
                if (isset($tools[$toolName])) {
                    Log::channel('ai-engine')->info('MinimalAIOrchestrator: FOUND TOOL', [
                        'tool_name' => $toolName,
                        'config' => class_basename($configClass),
                    ]);
                    $tool = $tools[$toolName];
                    $handler = $tool['handler'] ?? null;
                    
                    if (!$handler || !is_callable($handler)) {
                        continue;
                    }
                    
                    // Extract parameters from conversation context using handler
                    $params = \LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor::extractWithMetadata(
                        $message,
                        $context,
                        $tool['parameters'] ?? [],
                        $modelName ?? null
                    );
                    
                    Log::channel('ai-engine')->info('Executing tool handler', [
                        'tool_name' => $toolName,
                        'params' => $params,
                    ]);
                    
                    // Execute the tool
                    $result = $handler($params);
                    
                    if ($result['success'] ?? false) {
                        return AgentResponse::success(
                            message: $result['message'] ?? 'Operation completed successfully',
                            context: $context,
                            data: $result
                        );
                    }
                    
                    return AgentResponse::failure(
                        message: $result['error'] ?? 'Operation failed',
                        context: $context
                    );
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Tool execution failed', [
                    'tool_name' => $toolName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Tool not found - fallback to RAG to handle it
        Log::channel('ai-engine')->warning('Tool not found in configs, routing to RAG', [
            'tool_name' => $toolName,
        ]);
        
        return $this->executeSearchRAG($message, $context, $options);
    }

    protected function executeStartCollector(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $collectorName = $decision['resource_name'];

        if (!$collectorName) {
            return AgentResponse::failure(
                message: 'No collector specified',
                context: $context
            );
        }

        Log::channel('ai-engine')->info('MinimalAIOrchestrator starting collector', [
            'collector_name' => $collectorName,
            'message' => substr($message, 0, 100),
        ]);

        // Get discovered collectors from discovery service (works on all nodes, not just master)
        // Discovery service caches collector metadata including node information
        $discoveryService = app(\LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService::class);
        $discoveredCollectors = $discoveryService->discoverCollectors(useCache: true, includeRemote: true);

        // Check if this collector exists and if it's from a remote node
        if (isset($discoveredCollectors[$collectorName])) {
            $collectorInfo = $discoveredCollectors[$collectorName];

            // If collector is from a remote node, route to that node
            if (($collectorInfo['source'] ?? 'local') === 'remote' && !empty($collectorInfo['node_slug'])) {
                Log::channel('ai-engine')->info('Routing collector request to node', [
                    'collector_name' => $collectorName,
                    'node' => $collectorInfo['node_slug'],
                    'node_name' => $collectorInfo['node_name'] ?? '',
                ]);

                return $this->executeRouteToNode(
                    ['resource_name' => $collectorInfo['node_slug']],
                    $message,
                    $context,
                    $options
                );
            }

            // Collector is local - try to instantiate it from the class
            if (!empty($collectorInfo['class']) && class_exists($collectorInfo['class'])) {
                $configClass = $collectorInfo['class'];

                // Create config instance
                if (method_exists($configClass, 'create')) {
                    $config = $configClass::create();
                } else {
                    $config = new $configClass();
                }

                Log::channel('ai-engine')->info('Starting local autonomous collector', [
                    'collector_name' => $collectorName,
                    'class' => $configClass,
                ]);

                $handler = app(\LaravelAIEngine\Services\Agent\Handlers\AutonomousCollectorHandler::class);

                return $handler->handle($message, $context, array_merge($options, [
                    'action' => 'start_autonomous_collector',
                    'collector_match' => [
                        'name' => $collectorName,
                        'config' => $config,
                        'description' => $collectorInfo['description'] ?? '',
                    ],
                ]));
            }
        }

        // Collector not found in discovery
        $match = \LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry::findConfigForMessage($message);

        if (!$match) {
            Log::channel('ai-engine')->error('Collector not found', [
                'collector_name' => $collectorName,
                'discovered_collectors' => array_keys($discoveredCollectors),
            ]);

            return AgentResponse::failure(
                message: "Collector '{$collectorName}' not available",
                context: $context
            );
        }

        $handler = app(AutonomousCollectorHandler::class);

        return $handler->handle($message, $context, array_merge($options, [
            'action' => 'start_autonomous_collector',
            'collector_match' => $match,
        ]));
    }

    protected function executeResumeSession(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $sessionStack = $context->get('session_stack', []);

        if (empty($sessionStack)) {
            return AgentResponse::conversational(
                message: "There's no paused session to resume.",
                context: $context
            );
        }

        $pausedSession = array_pop($sessionStack);
        unset($pausedSession['paused_at']);
        unset($pausedSession['paused_reason']);

        $context->set('autonomous_collector', $pausedSession);

        if (empty($sessionStack)) {
            $context->forget('session_stack');
        } else {
            $context->set('session_stack', $sessionStack);
        }

        $collectorName = $pausedSession['config_name'];
        $resumeMessage = "Welcome back! Let's continue with your {$collectorName}.";

        return AgentResponse::needsUserInput(
            message: $resumeMessage,
            context: $context
        );
    }

    protected function executePauseAndHandle(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $activeCollector = $context->get('autonomous_collector');

        if ($activeCollector) {
            $sessionStack = $context->get('session_stack', []);
            $activeCollector['paused_at'] = now()->toIso8601String();
            $sessionStack[] = $activeCollector;

            $context->set('session_stack', $sessionStack);
            $context->forget('autonomous_collector');

            Log::channel('ai-engine')->info('Session paused', [
                'collector' => $activeCollector['config_name'],
            ]);

            // Now handle the new request - fallback to conversational
            return $this->executeSearchRAG($message, $context, $options);
        }

        // No active collector to pause - this shouldn't happen, fallback to conversational
        Log::channel('ai-engine')->warning('pause_and_handle called with no active collector');
        return $this->executeSearchRAG($message, $context, $options);
    }

    protected function executeRouteToNode(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $nodeSlug = $decision['resource_name'];

        if (!$nodeSlug) {
            return $this->executeSearchRAG($message, $context, $options);
        }

        $node = $this->nodeRegistry->getNode($nodeSlug);

        if (!$node) {
            return $this->executeSearchRAG($message, $context, $options);
        }

        // Extract user token and forwardable headers for authentication
        $userToken = request()->bearerToken();
        $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

        // Merge authentication headers
        $headers = array_merge($forwardHeaders, [
            'X-Forwarded-From-Node' => config('app.name'),
            'X-User-Token' => $userToken,
        ]);

        // Add headers to options for forwarding
        $options['headers'] = $headers;
        $options['user_token'] = $userToken;

        $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);
        $response = $router->forwardChat($node, $message, $context->sessionId, $options, $context->userId);

        if ($response['success']) {
            $context->set('routed_to_node', [
                'node_slug' => $nodeSlug,
                'node_name' => $node->name,
            ]);

            return AgentResponse::success(
                message: $response['response'],
                context: $context,
                data: $response
            );
        }

        return $this->executeSearchRAG($message, $context, $options);
    }

    protected function executeSearchRAG(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Use AutonomousRAGAgent for knowledge base search
        $ragAgent = app(AutonomousRAGAgent::class);

        $conversationHistory = $context->conversationHistory ?? [];

        Log::channel('ai-engine')->info('Executing RAG search', [
            'message' => substr($message, 0, 100),
            'session_id' => $context->sessionId,
        ]);

        $result = $ragAgent->process(
            $message,
            $context->sessionId,
            $context->userId,
            $conversationHistory,
            $options
        );

        // Check if RAG agent wants to exit to orchestrator for CRUD operations
        if (!empty($result['exit_to_orchestrator'])) {
            $newMessage = $result['message'] ?? $message;

            Log::channel('ai-engine')->info('RAG agent exiting to orchestrator for CRUD operation', [
                'original_message' => substr($message, 0, 100),
                'new_message' => substr($newMessage, 0, 100),
            ]);

            // Skip AI decision and directly try to match collectors
            // This prevents infinite loop where AI decides search_rag again
            $options['skip_ai_decision'] = true;
            return $this->process($newMessage, $context->sessionId, $context->userId, $options);
        }

        if ($result['success'] ?? false) {
            $responseText = $result['response'] ?? 'No results found.';

            // Store metadata
            $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
            $context->metadata['fast_path'] = $result['fast_path'] ?? false;

            $context->addAssistantMessage($responseText);

            return AgentResponse::conversational(
                message: $responseText,
                context: $context
            );
        }

        // Fallback to simple response
        return AgentResponse::conversational(
            message: "I couldn't find any relevant information. Could you please rephrase your question?",
            context: $context
        );
    }

    protected function executeConversational(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Simple conversational response without RAG search
        $aiEngine = app(AIEngineService::class);

        Log::channel('ai-engine')->info('Executing conversational response', [
            'message' => substr($message, 0, 100),
            'session_id' => $context->sessionId,
        ]);

        $conversationHistory = $context->conversationHistory ?? [];
        $historyText = '';
        foreach (array_slice($conversationHistory, -3) as $msg) {
            $historyText .= "{$msg['role']}: {$msg['content']}\n";
        }

        $prompt = <<<PROMPT
You are a helpful AI assistant. Respond naturally to the user's message.

Recent conversation:
{$historyText}

User: {$message}

Respond in a friendly, helpful manner.
PROMPT;

        $aiResponse = $aiEngine->generate(new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from($options['engine'] ?? 'openai'),
            model: EntityEnum::from($options['model'] ?? 'gpt-4o-mini'),
            maxTokens: 200,
            temperature: 0.7,
        ));

        $responseText = $aiResponse->getContent();
        $context->addAssistantMessage($responseText);

        return AgentResponse::conversational(
            message: $responseText,
            context: $context
        );
    }
}
