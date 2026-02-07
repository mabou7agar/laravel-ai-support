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
use Illuminate\Support\Facades\Cache;

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
    ) {
    }

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

        // RULE 0: Check for option selection from previous message
        // This must come before other routing to avoid treating "1" as a position query
        if ($this->detectOptionSelection($message, $context)) {
            Log::channel('ai-engine')->info('Detected option selection from previous message');
            $response = $this->handleOptionSelection($message, $context, $options);
            if ($response) {
                $this->contextManager->save($context);
                return $response;
            }
            // If handler returns null, fall through to normal processing
        }

        // RULE 1: Active session? Continue it (no AI needed)
        if ($context->has('autonomous_collector')) {
            Log::channel('ai-engine')->info('Continuing active collector session');
            return $this->continueCollector($message, $context, $options);
        }

        if ($context->has('routed_to_node')) {
            // Check if the new message is still related to the routed node's domain
            // If it's a completely different topic (like listing mails), handle locally
            if ($this->shouldContinueRoutedSession($message, $context)) {
                Log::channel('ai-engine')->info('Continuing routed node session');
                return $this->continueNode($message, $context, $options);
            }

            // Different topic detected - clear routing and process locally
            Log::channel('ai-engine')->info('New topic detected, clearing routed node session', [
                'previous_node' => $context->get('routed_to_node')['node_slug'] ?? 'unknown',
                'new_message' => substr($message, 0, 100),
            ]);
            $context->forget('routed_to_node');
            // Continue to askAI below
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

        // Check if collector wants to exit and reroute
        if ($response->message === 'exit_and_reroute') {
            Log::channel('ai-engine')->info('Collector exited - rerouting message', [
                'original_message' => $message,
            ]);

            // Re-process the message as a fresh query (collector already cleared state)
            return $this->askAI($message, $context, $options);
        }

        $context->addAssistantMessage($response->message);
        $this->contextManager->save($context);

        return $response;
    }

    /**
     * Determine if we should continue routing to the remote node
     * or if the new message is about a different topic that should be handled locally
     *
     * Uses AI to detect context shift instead of hardcoded patterns
     */
    protected function shouldContinueRoutedSession(string $message, UnifiedActionContext $context): bool
    {
        $nodeInfo = $context->get('routed_to_node');
        $nodeSlug = $nodeInfo['node_slug'] ?? null;

        if (!$nodeSlug) {
            return false;
        }

        // Get the node to check its collections/domain
        $node = $this->nodeRegistry->getNode($nodeSlug);
        if (!$node) {
            return false;
        }

        // Explicit keyword-based checks for common context shifts
        // This prevents AI from incorrectly classifying obvious shifts
        $messageLower = strtolower($message);

        // If user asks about emails/mail while on a non-email node, it's DIFFERENT
        if (preg_match('/\b(email|mail|inbox|message)\b/', $messageLower)) {
            $nodeCollectionsStr = strtolower(json_encode($node->collections ?? []));
            // If node doesn't handle emails, this is a context shift
            if (!str_contains($nodeCollectionsStr, 'email') && !str_contains($nodeCollectionsStr, 'mail')) {
                Log::channel('ai-engine')->info('Explicit context shift detected (email query on non-email node)', [
                    'node' => $nodeSlug,
                    'message' => substr($message, 0, 100),
                ]);
                return false;
            }
        }

        // Get recent conversation history for context
        $conversationHistory = $context->conversationHistory ?? [];
        $recentMessages = array_slice($conversationHistory, -3);
        $historyText = '';
        foreach ($recentMessages as $msg) {
            $historyText .= "{$msg['role']}: {$msg['content']}\n";
        }

        // Build AI prompt to detect context shift
        $nodeCollections = !empty($node->collections) ? json_encode($node->collections, JSON_PRETTY_PRINT) : 'general operations';
        $nodeName = $node->name ?? $nodeSlug;

        $prompt = <<<PROMPT
You are analyzing if a new user message is related to the current conversation context or if it's a completely different topic.

Current Context:
- Active Node: {$nodeName}
- Node Handles: {$nodeCollections}
- Recent Conversation:
{$historyText}

New User Message: "{$message}"

Determine if this new message is:
1. RELATED: The message is about the same domain/topic that the active node handles
   - Example: If node handles invoices/bills, and user asks "show invoice #5" → RELATED
   - Example: If node handles invoices/bills, and user asks "list customers" → RELATED (if node handles customers)
   
2. DIFFERENT: The message is about a completely different domain that the active node does NOT handle
   - Example: If node handles invoices/bills, and user asks "list emails" → DIFFERENT (emails are not in node's domain)
   - Example: If node handles invoices/bills, and user asks "send email" → DIFFERENT

CRITICAL: Check if the new message topic is within the node's collections/domain. If the topic is NOT listed in "Node Handles", respond DIFFERENT.

Respond with ONLY one word: "RELATED" or "DIFFERENT"
PROMPT;

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from(config('ai-engine.default', 'openai')),
                model: EntityEnum::from(config('ai-engine.orchestration_model', 'gpt-4o-mini')),
                maxTokens: 10,
                temperature: 0.0
            );

            $aiResponse = $this->ai->generate($request);
            $decision = trim(strtoupper($aiResponse->getContent()));

            Log::channel('ai-engine')->info('AI context shift detection', [
                'message' => substr($message, 0, 100),
                'node' => $nodeSlug,
                'decision' => $decision,
            ]);

            // If AI says DIFFERENT, don't continue routing
            if (str_contains($decision, 'DIFFERENT')) {
                Log::channel('ai-engine')->info('AI detected context shift, handling locally', [
                    'node' => $nodeSlug,
                    'message' => substr($message, 0, 100),
                ]);
                return false;
            }

            // If AI says RELATED, continue routing
            return true;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI context detection failed, defaulting to continue routing', [
                'error' => $e->getMessage(),
                'node' => $nodeSlug,
            ]);

            // On error, default to continuing the session (safer fallback)
            return true;
        }
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
                data: $response,
                context: $context
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

    protected function getUserProfile(?string $userId): string
    {
        if (!$userId) {
            return "- No user profile available";
        }

        try {
            // Fetch user from database
            $user = \App\Models\User::find($userId);

            if (!$user) {
                return "- User ID: {$userId} (profile not found)";
            }

            $profile = [];
            $profile[] = "- Name: {$user->name}";
            $profile[] = "- Email: {$user->email}";

            // Add additional fields if they exist
            if (isset($user->company)) {
                $profile[] = "- Company: {$user->company}";
            }
            if (isset($user->role)) {
                $profile[] = "- Role: {$user->role}";
            }
            if (isset($user->preferences) && is_array($user->preferences)) {
                $profile[] = "- Preferences: " . json_encode($user->preferences);
            }

            return implode("\n", $profile);

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to fetch user profile', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return "- User ID: {$userId}";
        }
    }

    protected function buildPrompt(
        string $message,
        array $resources,
        UnifiedActionContext $context
    ): string {
        $history = $this->formatHistory($context);
        $pausedSessions = $context->get('session_stack', []);
        $discovery = new \LaravelAIEngine\Services\Node\NodeMetadataDiscovery();
        $localNodeMeta = $discovery->discover();
        $localNodeMeta['slug'] = 'local';

        // Get user profile information
        $userId = $context->userId;
        $userProfile = $this->getUserProfile($userId);

        return <<<PROMPT
You are an AI orchestrator. Decide what to do with this message.

USER PROFILE:
{$userProfile}

CONVERSATION HISTORY:
{$history}

PAUSED SESSIONS: {$this->formatPausedSessions($pausedSessions)}

AVAILABLE RESOURCES:

**Autonomous Collectors** (AI-guided multi-turn data collection):
{$this->formatCollectors($resources['collectors'])}

**Availble Local Collections**:
{$this->formatCollectors($localNodeMeta['collections'])}

**Model Tools** (Direct operations):
{$this->formatTools($resources['tools'])}

**Remote Nodes** (Specialized services):
{$this->formatNodes($resources['nodes'])}

**Local Node** (Specialized services):
{$this->formatNodes([$localNodeMeta])}

USER: "{$message}"

Analyze the conversation history and user's message. If the conversation context shows the user is working with a specific entity or the assistant's last response came from a remote node, continue routing to that same node for follow-up questions.
DEPENDS ON NODES DOMAIN YOU SHOULD DECIDE TO ROUTE using ( route_to_node action )
Choose the most appropriate action:
- start_collector: When user wants to create, update, or delete data
- search_rag: When user wants to view, list, search, or get information from LOCAL models (emails)
- conversational: For greetings and general chat
- route_to_node: When user wants to list, search, or view data from a REMOTE node domain
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
            $content = $msg['content'];

            // Check if message contains numbered options (1., 2., 3. or 1), 2), 3))
            $hasNumberedOptions = preg_match('/\b\d+[\.\)]\s+/m', $content);

            // Preserve numbered options fully, truncate others
            if ($hasNumberedOptions) {
                // Keep full content for numbered options (up to 1000 chars to be safe)
                $content = substr($content, 0, 1000);
            } else {
                // Normal truncation for other messages
                $content = substr($content, 0, 300);
            }

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
            $nodeName = $collector['node'] ?? 'local';
            $goal = $collector['goal'] ?? '';
            $lines[] = "   - Name :{$collector['name']} Goal: {$goal} Description : {$collector['description']} Node: {$nodeName} ";
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
            return "   (No nodes available)";
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

                    // First, check for suggested_actions in context metadata
                    $params = [];
                    $suggestedActions = $context->metadata['suggested_actions'] ?? [];

                    foreach ($suggestedActions as $action) {
                        if (($action['tool'] ?? $action['action'] ?? null) === $toolName) {
                            $params = $action['params'] ?? [];
                            Log::channel('ai-engine')->info('Using params from suggested_actions', [
                                'tool_name' => $toolName,
                                'params' => $params,
                            ]);
                            break;
                        }
                    }

                    // If no suggested_actions, extract from conversation context
                    if (empty($params)) {
                        $params = \LaravelAIEngine\Services\Agent\Handlers\ToolParameterExtractor::extractWithMetadata(
                            $message,
                            $context,
                            $tool['parameters'] ?? [],
                            $modelName ?? null
                        );
                    }

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

    /**
     * Detect if user is selecting from numbered options in previous message
     */
    protected function detectOptionSelection(string $message, UnifiedActionContext $context): bool
    {
        // Check if message is just a number
        $trimmed = trim($message);
        if (!is_numeric($trimmed)) {
            return false;
        }

        $optionNumber = (int) $trimmed;
        if ($optionNumber < 1 || $optionNumber > 10) {
            return false; // Reasonable limit for options
        }

        // Check if last assistant message contained numbered options
        $history = $context->conversationHistory ?? [];
        if (empty($history)) {
            return false;
        }

        // Get last assistant message
        $lastAssistantMessage = null;
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMessage = $history[$i]['content'] ?? '';
                break;
            }
        }

        if (!$lastAssistantMessage) {
            return false;
        }

        // Check if it contains numbered list (1., 2., 3., etc. or 1), 2), 3), etc.)
        $hasNumberedOptions = preg_match('/\b\d+[\.\)]\s+/m', $lastAssistantMessage);

        return $hasNumberedOptions;
    }

    /**
     * Handle option selection from previous message
     */
    protected function handleOptionSelection(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): ?AgentResponse {
        $optionNumber = (int) trim($message);

        // Get last assistant message
        $history = $context->conversationHistory ?? [];
        $lastAssistantMessage = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? '') === 'assistant') {
                $lastAssistantMessage = $history[$i]['content'] ?? '';
                break;
            }
        }

        Log::channel('ai-engine')->info('Handling option selection', [
            'option_number' => $optionNumber,
            'last_message_preview' => substr($lastAssistantMessage, 0, 200),
        ]);

        // Check if it's about email reply
        if (
            str_contains(strtolower($lastAssistantMessage), 'reply') &&
            str_contains(strtolower($lastAssistantMessage), 'email')
        ) {
            return $this->handleEmailReplyOption($optionNumber, $context, $options);
        }

        // Add more context handlers here as needed
        // For now, return null to fall through to normal processing
        return null;
    }

    /**
     * Handle email reply option selection
     */
    protected function handleEmailReplyOption(
        int $optionNumber,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // Get email context from conversation history
        $history = $context->conversationHistory ?? [];
        $emailSubject = '';
        $emailFrom = '';
        $emailId = null;
        $emailPreview = '';

        // Look for email details in recent messages
        foreach (array_reverse($history) as $msg) {
            $content = $msg['content'] ?? '';

            // Extract email metadata
            if (preg_match('/\*\*Subject:\*\*\s*(.+)/i', $content, $matches)) {
                $emailSubject = trim($matches[1]);
            }
            if (preg_match('/\*\*From:\*\*\s*(.+)/i', $content, $matches)) {
                $emailFrom = trim($matches[1]);
            }
            if (preg_match('/\*\*Preview:\*\*\s*(.+)/i', $content, $matches)) {
                $emailPreview = trim(strip_tags($matches[1]));
            }

            // Try to extract email ID from entity_ids in last_entity_list
            if ($emailSubject && $emailFrom) {
                break;
            }
        }

        // Get email ID from last query state
        $lastEntityList = $context->metadata['last_entity_list'] ?? [];
        if (!empty($lastEntityList['entity_ids'])) {
            // Use the first/only email ID from the list
            $emailId = $lastEntityList['entity_ids'][0] ?? null;
        }

        // If no email ID found, try to get from session cache
        if (!$emailId) {
            $sessionId = $context->sessionId;
            $queryState = Cache::get("rag_query_state:{$sessionId}");
            if ($queryState && !empty($queryState['entity_ids'])) {
                $emailId = $queryState['entity_ids'][0] ?? null;
            }
        }

        // Use AI to generate contextual reply based on option number
        $optionDescriptions = [
            1 => "confirm and acknowledge the email positively",
            2 => "inquire about additional details or ask clarifying questions",
            3 => "report a concern or issue that needs to be addressed",
        ];

        $optionIntent = $optionDescriptions[$optionNumber] ?? $optionDescriptions[1];

        // Generate reply using AI
        $aiEngine = app(AIEngineService::class);
        $prompt = <<<PROMPT
Generate a professional email reply to {$optionIntent}.

Email context:
From: {$emailFrom}
Subject: {$emailSubject}
Preview: {$emailPreview}

Write a concise, professional reply (2-3 sentences) that addresses the intent.
PROMPT;

        try {
            $aiResponse = $aiEngine->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openrouter'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 150,
                temperature: 0.7,
            ));

            $replyBody = trim($aiResponse->getContent());
        } catch (\Exception $e) {
            // Fallback to simple template if AI fails
            $replyBody = "Thank you for your email regarding {$emailSubject}. " .
                ($optionNumber == 2 ? "Could you please provide more details?" :
                    ($optionNumber == 3 ? "I'd like to discuss this further." :
                        "I appreciate the notification."));
        }

        $response = "Based on your selection, here's a suggested reply:\n\n\"{$replyBody}\"\n\nWould you like me to send this reply?";

        Log::channel('ai-engine')->info('Generated email reply suggestion', [
            'option_number' => $optionNumber,
            'email_id' => $emailId,
            'email_subject' => $emailSubject,
        ]);

        // Add suggested action for sending the reply
        $suggestedActions = [];

        if ($emailId) {
            $suggestedActions[] = [
                'label' => 'Send This Reply',
                'tool' => 'reply_to_email',
                'description' => 'Send the suggested reply to this email',
                'params' => [
                    'email_id' => $emailId,
                    'body' => $replyBody,
                    'reply_all' => false,
                ]
            ];
        } else {
            Log::channel('ai-engine')->warning('Could not find email_id for reply action');
        }

        $context->addAssistantMessage($response);
        $context->metadata['suggested_actions'] = $suggestedActions;

        return AgentResponse::conversational(
            message: $response,
            context: $context
        );
    }
}
