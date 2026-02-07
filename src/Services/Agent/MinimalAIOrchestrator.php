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

        // RULE 2b: Check for positional reference to previous list
        if ($this->detectPositionalReference($message, $context)) {
            Log::channel('ai-engine')->info('Detected positional reference to previous list');
            return $this->handlePositionalReference($message, $context, $options);
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

        $node = $this->resolveNodeForRouting($nodeSlug);

        if (!$node) {
            Log::channel('ai-engine')->warning('Unable to continue routed session: node not found', [
                'requested_node' => $nodeSlug,
                'session_id' => $context->sessionId,
            ]);
            $context->forget('routed_to_node');
            return $this->askAI($message, $context, $options);
        }
        $options = $this->buildNodeForwardOptions($options);

        $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);
        $response = $router->forwardChat($node, $message, $context->sessionId, $options, $context->userId);

        if ($response['success']) {
            $agentResponse = AgentResponse::success(
                message: $response['response'],
                data: $response,
                context: $context
            );

            $this->appendAssistantMessageIfNew($context, $response['response']);
            $this->contextManager->save($context);

            return $agentResponse;
        }

        Log::channel('ai-engine')->warning('Continue routed session failed', [
            'node' => $node->slug,
            'error' => $response['error'] ?? 'Unknown error',
            'session_id' => $context->sessionId,
        ]);

        $failureMessage = $this->formatNodeRoutingFailureMessage($node->slug, $node->url, $response['error'] ?? null);
        $this->appendAssistantMessageIfNew($context, $failureMessage);
        $this->contextManager->save($context);

        return AgentResponse::failure(
            message: $failureMessage,
            data: $response,
            context: $context
        );
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

            // Extract entity metadata from response if available
            $metadata = [];
            if (!empty($response->metadata['entity_ids'])) {
                $metadata['entity_ids'] = $response->metadata['entity_ids'];
                $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';

                // Clear stale selected_entity_context when new entity list is returned
                unset($context->metadata['selected_entity_context']);
                Log::channel('ai-engine')->info('Cleared stale selected_entity_context due to new entity list');
            }

            $this->appendAssistantMessageIfNew($context, $response->message, $metadata);
            $this->contextManager->save($context);

            return $response;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AI orchestration failed', [
                'error' => $e->getMessage(),
            ]);

            $response = $this->executeSearchRAG($message, $context, $options);

            // Extract entity metadata from RAG response
            $metadata = [];
            if (!empty($response->metadata['entity_ids'])) {
                $metadata['entity_ids'] = $response->metadata['entity_ids'];
                $metadata['entity_type'] = $response->metadata['entity_type'] ?? 'item';

                // Clear stale selected_entity_context when new entity list is returned
                unset($context->metadata['selected_entity_context']);
                Log::channel('ai-engine')->info('Cleared stale selected_entity_context due to new entity list (RAG fallback)');
            }

            $this->appendAssistantMessageIfNew($context, $response->message, $metadata);
            $this->contextManager->save($context);

            return $response;
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
        $selectedEntityContext = $this->formatSelectedEntityContext($context);

        // Get user profile information
        $userId = $context->userId;
        $userProfile = $this->getUserProfile($userId);

        // Get entity metadata from last assistant message
        $entityContext = $this->formatEntityMetadata($context);

        return <<<PROMPT
You are an AI orchestrator. Decide what to do with this message.

USER PROFILE:
{$userProfile}

CONVERSATION HISTORY:
{$history}

{$entityContext}

SELECTED ENTITY CONTEXT:
{$selectedEntityContext}

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
If SELECTED ENTITY CONTEXT is present, preserve that context and avoid asking the user to repeat IDs.
If ENTITY CONTEXT is present and user refers to positions (1, 2, first, second, etc.), use the entity IDs from that context.
For follow-up requests about the selected entity (details, actions, drafts, updates), pick the action/tool that can use that selected entity directly.
DEPENDS ON NODES DOMAIN YOU SHOULD DECIDE TO ROUTE using ( route_to_node action ) AND NEVER ROUTE IN CASE NODE IS "local"
Choose the most appropriate action:
- start_collector: When user wants to create, update, or delete data
- search_rag: When user wants to view, list, search, or get information from LOCAL models (emails)
- conversational: For greetings and general chat
- route_to_node: When user wants to list, search, or view data from a REMOTE node domain
- resume_session: When user says "back" or "resume"

Match the user's intent to available collectors based on their goals.
NEVER USE TOOL IF a USER WANTs to ENHANCMENT INTO LAST CONVERSATION OR TO SUGGEST THINGS
USE VECTOR IF YOU WANT to REPLY DEPENDS ON UNDERSTANDING

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

    /**
     * Provide compact selected-entity context for AI routing (model-agnostic).
     */
    protected function formatSelectedEntityContext(UnifiedActionContext $context): string
    {
        $selected = $this->getSelectedEntityContext($context);
        if (!$selected) {
            return "(none)";
        }

        return json_encode($selected, JSON_PRETTY_PRINT);
    }

    /**
     * Get selected entity context from metadata, when available.
     */
    protected function getSelectedEntityContext(UnifiedActionContext $context): ?array
    {
        $selected = $context->metadata['selected_entity_context'] ?? null;
        if (is_array($selected) && !empty($selected['entity_id'])) {
            return $selected;
        }

        $legacy = $context->metadata['last_selected_option'] ?? null;
        if (is_array($legacy) && !empty($legacy['entity_id'])) {
            return [
                'entity_id' => (int) $legacy['entity_id'],
                'entity_type' => $legacy['entity_type'] ?? null,
                'model_class' => $legacy['model_class'] ?? null,
                'source_node' => $legacy['source_node'] ?? null,
                'selected_via' => 'numbered_option',
            ];
        }

        return null;
    }

    /**
     * Format entity metadata from last assistant message for AI prompt
     */
    protected function formatEntityMetadata(UnifiedActionContext $context): string
    {
        // Get last assistant message
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if ($msg['role'] === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                $entityIds = $msg['metadata']['entity_ids'];
                $entityType = $msg['metadata']['entity_type'] ?? 'item';

                // Format as compact list
                $formatted = "ENTITY CONTEXT (from last response):\n";
                $formatted .= "Type: {$entityType}\n";
                $formatted .= "IDs: " . json_encode($entityIds) . "\n";
                $formatted .= "Note: If user refers to positions (1, 2, first, etc.), map to these IDs in order.\n";

                return $formatted;
            }
        }

        return "";
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
                    $modelName = method_exists($configClass, 'getName')
                        ? $configClass::getName()
                        : null;

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
                            $modelName
                        );
                    }

                    $selectedEntity = $this->getSelectedEntityContext($context);
                    $params = $this->bindSelectedEntityToToolParams(
                        $toolName,
                        $params,
                        $selectedEntity,
                        $tool['parameters'] ?? []
                    );

                    Log::channel('ai-engine')->info('Executing tool handler', [
                        'tool_name' => $toolName,
                        'params' => $params,
                        'selected_entity_id' => $selectedEntity['entity_id'] ?? null,
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
        $requestedResource = trim((string) ($decision['resource_name'] ?? ''));
        if ($requestedResource === '' || $requestedResource === 'local') {
            Log::channel('ai-engine')->warning('route_to_node decision without resource_name, falling back to RAG', [
                'message' => substr($message, 0, 120),
                'session_id' => $context->sessionId,
            ]);
            return $this->executeSearchRAG($message, $context, $options);
        }

        $node = $this->resolveNodeForRouting($requestedResource);
        if (!$node) {
            Log::channel('ai-engine')->warning('route_to_node could not resolve target node', [
                'resource_name' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            return AgentResponse::failure(
                message: "I couldn't find a remote node matching '{$requestedResource}'.",
                context: $context
            );
        }

        Log::channel('ai-engine')->info('Routing message to remote node', [
            'requested_resource' => $requestedResource,
            'resolved_node' => $node->slug,
            'session_id' => $context->sessionId,
        ]);

        $options = $this->buildNodeForwardOptions($options);

        $router = app(\LaravelAIEngine\Services\Node\NodeRouterService::class);
        $response = $router->forwardChat($node, $message, $context->sessionId, $options, $context->userId);

        if ($response['success']) {
            $context->set('routed_to_node', [
                'node_slug' => $node->slug,
                'node_name' => $node->name,
            ]);

            return AgentResponse::success(
                message: $response['response'],
                context: $context,
                data: $response
            );
        }

        Log::channel('ai-engine')->warning('route_to_node failed; skipping local fallback to avoid mixed-domain results', [
            'requested_resource' => $requestedResource,
            'resolved_node' => $node->slug,
            'node_url' => $node->url,
            'error' => $response['error'] ?? 'Unknown error',
            'session_id' => $context->sessionId,
        ]);

        return AgentResponse::failure(
            message: $this->formatNodeRoutingFailureMessage($node->slug, $node->url, $response['error'] ?? null),
            data: $response,
            context: $context
        );
    }

    /**
     * Build consistent forwarding headers/options for node requests.
     */
    protected function buildNodeForwardOptions(array $options): array
    {
        $userToken = request()->bearerToken();
        $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

        $options['headers'] = array_merge($forwardHeaders, [
            'X-Forwarded-From-Node' => config('app.name'),
            'X-User-Token' => $userToken,
        ]);
        $options['user_token'] = $userToken;

        return $options;
    }

    /**
     * Resolve a route target to a node using slug/name/collection ownership matching.
     */
    protected function resolveNodeForRouting(string $resourceName): ?\LaravelAIEngine\Models\AINode
    {
        $resourceName = trim($resourceName);
        if ($resourceName === '') {
            return null;
        }

        $node = $this->nodeRegistry->getNode($resourceName);
        if ($node) {
            return $node;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]/', '', $resourceName));
        $nodes = $this->nodeRegistry->getAllNodes();

        $matchedNode = $nodes->first(function ($candidate) use ($normalized) {
            $slug = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $candidate->slug));
            $name = strtolower(preg_replace('/[^a-z0-9]/', '', (string) $candidate->name));
            return $slug === $normalized || $name === $normalized;
        });
        if ($matchedNode) {
            return $matchedNode;
        }

        $matchedByCollection = $this->nodeRegistry->findNodeForCollection($resourceName);
        if ($matchedByCollection) {
            return $matchedByCollection;
        }

        $singular = rtrim($resourceName, 's');
        if ($singular !== $resourceName) {
            $matchedByCollection = $this->nodeRegistry->findNodeForCollection($singular);
            if ($matchedByCollection) {
                return $matchedByCollection;
            }
        }

        return null;
    }

    protected function formatNodeRoutingFailureMessage(string $nodeSlug, ?string $nodeUrl, ?string $error = null): string
    {
        $summary = $error ? preg_replace('/\s+/', ' ', trim($error)) : 'unknown routing error';
        if (is_string($summary) && strlen($summary) > 220) {
            $summary = substr($summary, 0, 220) . '...';
        }

        $nodeLocation = $nodeUrl ? " at {$nodeUrl}" : '';

        return "I couldn't reach remote node '{$nodeSlug}'{$nodeLocation} ({$summary}). I did not run a local fallback query to avoid mixed-domain results. Please verify the node is running and try again.";
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

        $selectedEntity = $this->getSelectedEntityContext($context);
        if ($selectedEntity) {
            $options['selected_entity'] = $selectedEntity;
        }

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
            if (!empty($result['metadata']) && is_array($result['metadata'])) {
                $context->metadata['rag_last_metadata'] = $result['metadata'];
            }
            if (!empty($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                $context->metadata['suggested_actions'] = array_values($result['suggested_actions']);
            } else {
                unset($context->metadata['suggested_actions']);
            }
            $this->captureSelectionStateFromResult($result, $context);

            // Extract entity metadata for conversation history
            $messageMetadata = [];
            if (!empty($result['metadata']['entity_ids'])) {
                $messageMetadata['entity_ids'] = $result['metadata']['entity_ids'];
                $messageMetadata['entity_type'] = $result['metadata']['entity_type'] ?? 'item';
            }

            return AgentResponse::conversational(
                message: $responseText,
                context: $context,
                metadata: $messageMetadata
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

        return AgentResponse::conversational(
            message: $responseText,
            context: $context
        );
    }

    /**
     * Avoid duplicate assistant messages when nested orchestration/fallback paths return the same text.
     */
    protected function appendAssistantMessageIfNew(UnifiedActionContext $context, string $message, array $metadata = []): void
    {
        $history = $context->conversationHistory ?? [];
        $lastMessage = !empty($history) ? end($history) : null;

        if (
            is_array($lastMessage) &&
            ($lastMessage['role'] ?? null) === 'assistant' &&
            ($lastMessage['content'] ?? null) === $message
        ) {
            return;
        }

        $context->addAssistantMessage($message, $metadata);
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
     * Detect if user is making a positional reference to a previous list
     */
    protected function detectPositionalReference(string $message, UnifiedActionContext $context): bool
    {
        // Check if message contains positional words/patterns
        $positionalPattern = '/\b(first|second|third|fourth|fifth|1st|2nd|3rd|4th|5th|the\s+\d+|number\s+\d+|\d+)\s*(one|email|invoice|item|entry)?\b/i';
        if (!preg_match($positionalPattern, $message)) {
            return false;
        }

        // Check if we have entity context in last assistant message
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if ($msg['role'] === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle positional reference by fetching full entity details
     */
    protected function handlePositionalReference(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        // 1. Extract position from message
        $position = $this->extractPosition($message);
        if ($position === null) {
            return AgentResponse::conversational(
                message: "I couldn't understand which item you're referring to. Could you be more specific?",
                context: $context
            );
        }

        // 2. Get entity ID from conversation metadata
        $entityData = $this->getEntityFromPosition($position, $context);
        if (!$entityData) {
            return AgentResponse::conversational(
                message: "I couldn't find item #{$position} in the previous list. Please check the number and try again.",
                context: $context
            );
        }

        Log::channel('ai-engine')->info('Resolved positional reference', [
            'position' => $position,
            'entity_id' => $entityData['id'],
            'entity_type' => $entityData['type'],
        ]);

        // 3. Fetch full entity details
        $fullEntity = $this->fetchEntityDetails(
            $entityData['id'],
            $entityData['type'],
            $context
        );

        if (!$fullEntity) {
            return AgentResponse::conversational(
                message: "I found the {$entityData['type']} but couldn't retrieve its details. Please try again.",
                context: $context
            );
        }

        // 4. Store in selected_entity_context for tools to use
        $context->metadata['selected_entity_context'] = [
            'entity_id' => $entityData['id'],
            'entity_type' => $entityData['type'],
            'entity_data' => $fullEntity,
            'selected_via' => 'positional_reference',
            'position' => $position,
            'suggested_action_content' => null, // Will be populated by AI if it suggests an action
        ];

        Log::channel('ai-engine')->info('Enriched context with entity details', [
            'entity_id' => $entityData['id'],
            'has_data' => !empty($fullEntity),
        ]);

        // Save context to persist entity data across conversation turns
        $this->contextManager->save($context);

        // 5. Re-process message with enriched context
        return $this->askAI($message, $context, $options);
    }

    /**
     * Extract position number from message
     */
    protected function extractPosition(string $message): ?int
    {
        // Try to match ordinal words
        $ordinals = [
            'first' => 1,
            'second' => 2,
            'third' => 3,
            'fourth' => 4,
            'fifth' => 5,
            '1st' => 1,
            '2nd' => 2,
            '3rd' => 3,
            '4th' => 4,
            '5th' => 5,
        ];

        foreach ($ordinals as $word => $position) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $message)) {
                return $position;
            }
        }

        // Try to match numbers
        if (preg_match('/\b(?:the\s+|number\s+)?(\d+)\b/i', $message, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Get entity ID and type from position in conversation metadata
     */
    protected function getEntityFromPosition(int $position, UnifiedActionContext $context): ?array
    {
        // Find last assistant message with entity_ids
        $messages = array_reverse($context->conversationHistory);
        foreach ($messages as $msg) {
            if ($msg['role'] === 'assistant' && !empty($msg['metadata']['entity_ids'])) {
                $entityIds = $msg['metadata']['entity_ids'];
                $entityType = $msg['metadata']['entity_type'] ?? 'item';

                // Position is 1-indexed, array is 0-indexed
                $index = $position - 1;
                if (isset($entityIds[$index])) {
                    return [
                        'id' => $entityIds[$index],
                        'type' => $entityType,
                    ];
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Fetch full entity details from RAG or DB
     */
    protected function fetchEntityDetails(int $entityId, string $entityType, UnifiedActionContext $context): ?array
    {
        try {
            // Map entity type to model class
            $modelClass = $this->getModelClassForEntityType($entityType);
            if (!$modelClass || !class_exists($modelClass)) {
                Log::channel('ai-engine')->warning('Unknown entity type for fetch', [
                    'entity_type' => $entityType,
                ]);
                return null;
            }

            // Fetch from database
            $entity = $modelClass::find($entityId);
            if (!$entity) {
                Log::channel('ai-engine')->warning('Entity not found in database', [
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $modelClass,
                ]);
                return null;
            }

            // Convert to array with all attributes
            $entityData = $entity->toArray();

            Log::channel('ai-engine')->info('Successfully fetched entity from database', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'has_keys' => array_keys($entityData),
            ]);

            return $entityData;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Error fetching entity details', [
                'entity_id' => $entityId,
                'entity_type' => $entityType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map entity type to model class
     */
    protected function getModelClassForEntityType(string $entityType): ?string
    {
        $mapping = [
            'email' => \LaravelAIEngine\Models\EmailCache::class,
            'invoice' => \App\Models\Invoice::class,
            'customer' => \App\Models\Customer::class,
            'product' => \App\Models\Product::class,
            // Add more mappings as needed
        ];

        return $mapping[strtolower($entityType)] ?? null;
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

        // Resolve hidden option metadata first (deterministic selection by ID)
        $selectionResponse = $this->handleStoredSelectionOption($optionNumber, $context, $options);
        if ($selectionResponse) {
            return $selectionResponse;
        }

        return null;
    }

    /**
     * Resolve numeric selection from hidden metadata (IDs + model metadata)
     */
    protected function handleStoredSelectionOption(
        int $optionNumber,
        UnifiedActionContext $context,
        array $options
    ): ?AgentResponse {
        $selection = $this->resolveSelectionOption($optionNumber, $context);
        if (!$selection) {
            return null;
        }

        Log::channel('ai-engine')->info('Resolved option from hidden selection map', [
            'option_number' => $optionNumber,
            'entity_id' => $selection['entity_id'] ?? null,
            'entity_type' => $selection['entity_type'] ?? null,
            'model_class' => $selection['model_class'] ?? null,
            'source_node' => $selection['source_node'] ?? null,
        ]);

        $entityId = $selection['entity_id'] ?? null;
        $modelClass = $selection['model_class'] ?? null;
        if ($entityId) {
            // Any previous quick-actions are now stale because user selected a new entity.
            unset($context->metadata['suggested_actions']);
        }

        // Fast path: query local model directly by hidden ID (no extra AI routing)
        if ($entityId && $modelClass && class_exists($modelClass)) {
            try {
                $query = $modelClass::query();

                if ($context->userId !== null && method_exists($modelClass, 'scopeForUser')) {
                    $query->forUser($context->userId);
                } elseif ($context->userId !== null) {
                    $instance = new $modelClass();
                    $table = $instance->getTable();
                    if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'user_id')) {
                        $query->where('user_id', $context->userId);
                    }
                }

                $record = $query->find($entityId);
                if ($record) {
                    $detail = $this->formatSelectedRecordDetails($record);

                    $responseText = "**Selected option {$optionNumber}**\n\n{$detail}";
                    $context->metadata['last_selected_option'] = [
                        'option' => $optionNumber,
                        'entity_id' => $entityId,
                        'entity_type' => $selection['entity_type'] ?? class_basename($modelClass),
                        'model_class' => $modelClass,
                        'source_node' => $selection['source_node'] ?? null,
                    ];
                    $context->metadata['selected_entity_context'] = [
                        'entity_id' => (int) $entityId,
                        'entity_type' => $selection['entity_type'] ?? class_basename($modelClass),
                        'model_class' => $modelClass,
                        'source_node' => $selection['source_node'] ?? null,
                        'selected_via' => 'numbered_option',
                        'detail_excerpt' => substr(trim(strip_tags($detail)), 0, 800),
                        'selected_at' => now()->toIso8601String(),
                        // 'entity_data' => $record->toArray(), // Add full entity data
                    ];
                    $this->appendAssistantMessageIfNew($context, $responseText);

                    return AgentResponse::conversational(
                        message: $responseText,
                        context: $context
                    );
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to resolve selected option from local model', [
                    'option_number' => $optionNumber,
                    'model_class' => $modelClass,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If the selected option belongs to a remote node AND we don't have a local model class, route to remote
        // Skip this if we already tried a local model (even if it failed) to avoid incorrect remote routing
        if (!empty($selection['source_node']) && $entityId && empty($modelClass)) {
            $context->metadata['selected_entity_context'] = [
                'entity_id' => (int) $entityId,
                'entity_type' => $selection['entity_type'] ?? 'record',
                'model_class' => $selection['model_class'] ?? null,
                'source_node' => $selection['source_node'],
                'selected_via' => 'numbered_option',
                'selected_at' => now()->toIso8601String(),
            ];
            $entityType = $selection['entity_type'] ?? 'record';
            $lookupMessage = "show details for {$entityType} id {$entityId}";
            $response = $this->executeRouteToNode(
                ['resource_name' => $selection['source_node']],
                $lookupMessage,
                $context,
                $options
            );
            $this->appendAssistantMessageIfNew($context, $response->message);
            return $response;
        }

        // Fallback: keep AI involved, but provide explicit hidden ID hint
        if ($entityId) {
            $context->metadata['selected_entity_context'] = [
                'entity_id' => (int) $entityId,
                'entity_type' => $selection['entity_type'] ?? 'item',
                'model_class' => $selection['model_class'] ?? null,
                'source_node' => $selection['source_node'] ?? null,
                'selected_via' => 'numbered_option',
                'selected_at' => now()->toIso8601String(),
            ];
            $entityType = $selection['entity_type'] ?? 'item';
            $response = $this->executeSearchRAG(
                "show full details for {$entityType} id {$entityId}",
                $context,
                $options
            );
            $this->appendAssistantMessageIfNew($context, $response->message);
            return $response;
        }

        return null;
    }

    /**
     * Ensure tool params target the currently selected entity when available.
     */
    protected function bindSelectedEntityToToolParams(
        string $toolName,
        array $params,
        ?array $selectedEntity,
        array $toolSchema = []
    ): array {
        if (!$selectedEntity || empty($selectedEntity['entity_id'])) {
            return $params;
        }

        $selectedId = (int) $selectedEntity['entity_id'];
        if ($selectedId <= 0) {
            return $params;
        }

        $singleIdKey = $this->findSingleEntityIdParamKey($toolSchema);
        if ($singleIdKey && ($params[$singleIdKey] ?? null) !== $selectedId) {
            Log::channel('ai-engine')->info('Overriding tool entity id from selected context', [
                'tool_name' => $toolName,
                'param_key' => $singleIdKey,
                'old_value' => $params[$singleIdKey] ?? null,
                'selected_entity_id' => $selectedId,
            ]);
            $params[$singleIdKey] = $selectedId;
        }

        if (isset($toolSchema['email_ids'])) {
            $existing = $params['email_ids'] ?? [];
            if (!is_array($existing) || $existing !== [$selectedId]) {
                $params['email_ids'] = [$selectedId];
            }
        }

        // Pass suggested action content if available (for action confirmation)
        if (!empty($selectedEntity['suggested_action_content'])) {
            $params['suggested_action_content'] = $selectedEntity['suggested_action_content'];

            Log::channel('ai-engine')->info('Added suggested action content to tool params', [
                'tool_name' => $toolName,
                'has_suggested_content' => true,
            ]);
        }

        // Pass full entity data if available (for tools that need complete entity)
        if (!empty($selectedEntity['entity_data'])) {
            $params['entity_data'] = $selectedEntity['entity_data'];

            Log::channel('ai-engine')->info('Added full entity data to tool params', [
                'tool_name' => $toolName,
                'entity_id' => $selectedId,
                'has_entity_data' => true,
                'entity_keys' => array_keys($selectedEntity['entity_data']),
            ]);
        }

        return $params;
    }

    /**
     * Detect primary entity id parameter from tool schema.
     */
    protected function findSingleEntityIdParamKey(array $toolSchema): ?string
    {
        if (empty($toolSchema)) {
            return null;
        }

        $excludedKeys = ['user_id', 'mailbox_id', 'session_id', 'node_id'];
        $candidates = [];

        foreach (array_keys($toolSchema) as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array($key, $excludedKeys, true)) {
                continue;
            }

            if ($key === 'id' || str_ends_with($key, '_id')) {
                $candidates[] = $key;
            }
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if (in_array('email_id', $candidates, true)) {
            return 'email_id';
        }

        if (in_array('id', $candidates, true)) {
            return 'id';
        }

        return null;
    }

    /**
     * Build richer details for selected items instead of returning list-level summaries.
     */
    protected function formatSelectedRecordDetails(object $record): string
    {
        if (method_exists($record, 'toRAGDetail')) {
            return (string) $record->toRAGDetail();
        }

        if (method_exists($record, 'toRAGContent')) {
            return (string) $record->toRAGContent();
        }

        if (method_exists($record, '__toString')) {
            return (string) $record;
        }

        if (!method_exists($record, 'toArray')) {
            return (string) json_encode($record, JSON_PRETTY_PRINT);
        }

        $data = $record->toArray();
        $detailFields = ['body_text', 'content', 'description', 'notes', 'message', 'text'];
        foreach ($detailFields as $field) {
            if (!empty($data[$field]) && is_string($data[$field])) {
                $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags($data[$field])));
                if ($clean !== '') {
                    $data[$field] = strlen($clean) > 1800 ? substr($clean, 0, 1800) . '...' : $clean;
                    break;
                }
            }
        }

        return (string) json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Resolve a numbered option from hidden selection metadata or cached query state
     */
    protected function resolveSelectionOption(int $optionNumber, UnifiedActionContext $context): ?array
    {
        $selectionMap = $context->metadata['selection_map'] ?? null;
        if (is_array($selectionMap)) {
            $expiresAt = $selectionMap['expires_at'] ?? null;
            if ($expiresAt && strtotime($expiresAt) < time()) {
                unset($context->metadata['selection_map']);
            } else {
                $options = $selectionMap['options'] ?? [];
                $option = $options[(string) $optionNumber] ?? $options[$optionNumber] ?? null;
                if (is_array($option) && !empty($option['entity_id'])) {
                    return $option;
                }
            }
        }

        // Fallback to query state cache when no explicit selection map is available
        $queryState = Cache::get("rag_query_state:{$context->sessionId}");
        if (!is_array($queryState) || empty($queryState['entity_ids'])) {
            return null;
        }

        $startPosition = (int) ($queryState['start_position'] ?? 1);
        $index = $optionNumber - $startPosition;
        if ($index < 0 || $index >= count($queryState['entity_ids'])) {
            return null;
        }

        $entityId = $queryState['entity_ids'][$index] ?? null;
        if (!$entityId) {
            return null;
        }

        return [
            'number' => $optionNumber,
            'entity_id' => $entityId,
            'entity_type' => $queryState['model'] ?? null,
            'model_class' => $queryState['model_class'] ?? null,
            'source_node' => null,
            'label' => null,
        ];
    }

    /**
     * Build hidden option -> entity map from RAG metadata so numeric picks stay stable.
     */
    protected function captureSelectionStateFromResult(array $result, UnifiedActionContext $context): void
    {
        $metadata = (isset($result['metadata']) && is_array($result['metadata']))
            ? $result['metadata']
            : [];
        $sources = (isset($metadata['sources']) && is_array($metadata['sources']))
            ? $metadata['sources']
            : [];
        $numberedOptions = (isset($metadata['numbered_options']) && is_array($metadata['numbered_options']))
            ? $metadata['numbered_options']
            : [];

        $queryState = Cache::get("rag_query_state:{$context->sessionId}");
        if (!is_array($queryState)) {
            $queryState = [];
        }

        $entityIds = $result['entity_ids'] ?? $metadata['entity_ids'] ?? $queryState['entity_ids'] ?? [];
        $entityType = $result['entity_type'] ?? $metadata['entity_type'] ?? $queryState['model'] ?? null;
        $defaultModelClass = $queryState['model_class'] ?? null;
        $defaultSourceNode = null;

        foreach ($sources as $sourceItem) {
            if (!is_array($sourceItem)) {
                continue;
            }

            if (!$defaultModelClass) {
                $candidateClass = $sourceItem['model_class'] ?? null;
                if (!empty($candidateClass) && $candidateClass !== 'Unknown') {
                    $defaultModelClass = $candidateClass;
                }
            }

            if (!$defaultSourceNode && !empty($sourceItem['source_node'])) {
                $defaultSourceNode = $sourceItem['source_node'];
            }
        }

        if (!empty($entityIds)) {
            $context->metadata['last_entity_list'] = [
                'entity_ids' => array_values($entityIds),
                'entity_type' => $entityType,
                'start_position' => $queryState['start_position'] ?? 1,
                'end_position' => $queryState['end_position'] ?? count($entityIds),
                'entity_data' => $queryState['entity_data'] ?? [],
            ];
        }

        $mapOptions = [];

        foreach (array_values(array_slice($numberedOptions, 0, 20)) as $idx => $option) {
            $number = isset($option['number']) && is_numeric($option['number'])
                ? (int) $option['number']
                : (isset($option['value']) && is_numeric($option['value']) ? (int) $option['value'] : null);

            if (!$number) {
                continue;
            }

            $sourceIndex = isset($option['source_index']) && is_numeric($option['source_index'])
                ? (int) $option['source_index']
                : null;
            $source = ($sourceIndex !== null && isset($sources[$sourceIndex]) && is_array($sources[$sourceIndex]))
                ? $sources[$sourceIndex]
                : [];

            $entityId = $source['model_id'] ?? $source['id'] ?? null;
            if ($entityId === null && isset($entityIds[$idx])) {
                $entityId = $entityIds[$idx];
            }

            $modelClass = $source['model_class'] ?? $defaultModelClass;
            $sourceNode = $source['source_node'] ?? $defaultSourceNode;

            $mapOptions[(string) $number] = [
                'number' => $number,
                'entity_id' => $entityId,
                'entity_type' => $source['model_type'] ?? $entityType,
                'model_class' => $modelClass,
                'source_node' => $sourceNode,
                'label' => $option['text'] ?? null,
            ];
        }

        // If options exist but AI output omitted source mapping, align options with extracted entity IDs by display order.
        if (!empty($mapOptions) && !empty($entityIds)) {
            $orderedOptionNumbers = array_keys($mapOptions);
            sort($orderedOptionNumbers, SORT_NUMERIC);

            $hasValidEntityId = false;
            foreach ($orderedOptionNumbers as $optionNumberKey) {
                if (!empty($mapOptions[(string) $optionNumberKey]['entity_id'])) {
                    $hasValidEntityId = true;
                    break;
                }
            }

            if (!$hasValidEntityId) {
                foreach ($orderedOptionNumbers as $idx => $optionNumberKey) {
                    if (!isset($entityIds[$idx])) {
                        continue;
                    }
                    $mapOptions[(string) $optionNumberKey]['entity_id'] = $entityIds[$idx];
                    $mapOptions[(string) $optionNumberKey]['model_class'] = $mapOptions[(string) $optionNumberKey]['model_class'] ?? $defaultModelClass;
                    $mapOptions[(string) $optionNumberKey]['source_node'] = $mapOptions[(string) $optionNumberKey]['source_node'] ?? $defaultSourceNode;
                }
            }
        }

        // Fallback for list responses without numbered_options metadata
        if (empty($mapOptions) && !empty($entityIds)) {
            $start = (int) ($queryState['start_position'] ?? 1);
            foreach (array_slice(array_values($entityIds), 0, 20) as $idx => $entityId) {
                $number = $start + $idx;
                $mapOptions[(string) $number] = [
                    'number' => $number,
                    'entity_id' => $entityId,
                    'entity_type' => $entityType,
                    'model_class' => $defaultModelClass,
                    'source_node' => $defaultSourceNode,
                    'label' => null,
                ];
            }
        }

        if (!empty($mapOptions)) {
            $context->metadata['selection_map'] = [
                'created_at' => now()->toIso8601String(),
                'expires_at' => now()->addMinutes(20)->toIso8601String(),
                'options' => $mapOptions,
            ];

            Log::channel('ai-engine')->debug('Stored hidden selection map', [
                'session_id' => $context->sessionId,
                'option_count' => count($mapOptions),
                'keys' => array_keys($mapOptions),
            ]);
            return;
        }

        // Clear stale map if current response doesn't provide selectable items
        unset($context->metadata['selection_map']);
    }

}
