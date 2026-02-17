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
        protected NodeRegistryService $nodeRegistry,
        protected ?IntentClassifierService $intentClassifier = null,
        protected ?DecisionPolicyService $decisionPolicyService = null,
        protected ?FollowUpStateService $followUpStateService = null,
        protected ?OrchestratorPromptBuilder $orchestratorPromptBuilder = null,
        protected ?OrchestratorDecisionParser $orchestratorDecisionParser = null,
        protected ?ToolExecutionCoordinator $toolExecutionCoordinator = null,
        protected ?CollectorExecutionCoordinator $collectorExecutionCoordinator = null,
        protected ?NodeRoutingCoordinator $nodeRoutingCoordinator = null,
        protected ?RoutedSessionPolicyService $routedSessionPolicyService = null,
        protected ?FollowUpDecisionAIService $followUpDecisionAIService = null,
        protected ?PositionalReferenceAIService $positionalReferenceAIService = null,
        protected ?PositionalReferenceCoordinator $positionalReferenceCoordinator = null,
        protected ?AgentPolicyService $agentPolicyService = null,
        protected ?AgentToolExecutor $agentToolExecutor = null,
        protected ?NextStepSuggestionService $nextStepSuggestionService = null,
        protected ?OrchestratorResourceDiscovery $resourceDiscovery = null,
        protected ?OrchestratorResponseFormatter $responseFormatter = null
    ) {
    }

    protected function getIntentClassifier(): IntentClassifierService
    {
        if ($this->intentClassifier === null) {
            $this->intentClassifier = app(IntentClassifierService::class);
        }

        return $this->intentClassifier;
    }

    protected function getDecisionPolicyService(): DecisionPolicyService
    {
        if ($this->decisionPolicyService === null) {
            $this->decisionPolicyService = app(DecisionPolicyService::class);
        }

        return $this->decisionPolicyService;
    }

    protected function getFollowUpStateService(): FollowUpStateService
    {
        if ($this->followUpStateService === null) {
            $this->followUpStateService = app(FollowUpStateService::class);
        }

        return $this->followUpStateService;
    }

    protected function getOrchestratorPromptBuilder(): OrchestratorPromptBuilder
    {
        if ($this->orchestratorPromptBuilder === null) {
            $this->orchestratorPromptBuilder = app(OrchestratorPromptBuilder::class);
        }

        return $this->orchestratorPromptBuilder;
    }

    protected function getOrchestratorDecisionParser(): OrchestratorDecisionParser
    {
        if ($this->orchestratorDecisionParser === null) {
            $this->orchestratorDecisionParser = app(OrchestratorDecisionParser::class);
        }

        return $this->orchestratorDecisionParser;
    }

    protected function getToolExecutionCoordinator(): ToolExecutionCoordinator
    {
        if ($this->toolExecutionCoordinator === null) {
            $this->toolExecutionCoordinator = app(ToolExecutionCoordinator::class);
        }

        return $this->toolExecutionCoordinator;
    }

    protected function getCollectorExecutionCoordinator(): CollectorExecutionCoordinator
    {
        if ($this->collectorExecutionCoordinator === null) {
            $this->collectorExecutionCoordinator = app(CollectorExecutionCoordinator::class);
        }

        return $this->collectorExecutionCoordinator;
    }

    protected function getNodeRoutingCoordinator(): NodeRoutingCoordinator
    {
        if ($this->nodeRoutingCoordinator === null) {
            $this->nodeRoutingCoordinator = app(NodeRoutingCoordinator::class);
        }

        return $this->nodeRoutingCoordinator;
    }

    protected function getRoutedSessionPolicyService(): RoutedSessionPolicyService
    {
        if ($this->routedSessionPolicyService === null) {
            $this->routedSessionPolicyService = app(RoutedSessionPolicyService::class);
        }

        return $this->routedSessionPolicyService;
    }

    protected function getFollowUpDecisionAIService(): FollowUpDecisionAIService
    {
        if ($this->followUpDecisionAIService === null) {
            try {
                $this->followUpDecisionAIService = app(FollowUpDecisionAIService::class);
            } catch (\Throwable $e) {
                // Fallback keeps unit tests and minimal containers working without explicit binding.
                $this->followUpDecisionAIService = new FollowUpDecisionAIService(
                    $this->ai,
                    $this->getIntentClassifier(),
                    $this->getDecisionPolicyService(),
                    $this->getFollowUpStateService(),
                    ['enabled' => false]
                );
            }
        }

        return $this->followUpDecisionAIService;
    }

    protected function getPositionalReferenceAIService(): PositionalReferenceAIService
    {
        if ($this->positionalReferenceAIService === null) {
            try {
                $this->positionalReferenceAIService = app(PositionalReferenceAIService::class);
            } catch (\Throwable $e) {
                $this->positionalReferenceAIService = new PositionalReferenceAIService(
                    $this->ai,
                    $this->getIntentClassifier(),
                    $this->getFollowUpStateService(),
                    ['enabled' => false]
                );
            }
        }

        return $this->positionalReferenceAIService;
    }

    protected function getPositionalReferenceCoordinator(): PositionalReferenceCoordinator
    {
        if ($this->positionalReferenceCoordinator === null) {
            try {
                $this->positionalReferenceCoordinator = app(PositionalReferenceCoordinator::class);
            } catch (\Throwable $e) {
                $this->positionalReferenceCoordinator = new PositionalReferenceCoordinator(
                    $this->getIntentClassifier(),
                    $this->getFollowUpStateService()
                );
            }
        }

        return $this->positionalReferenceCoordinator;
    }

    protected function getAgentPolicyService(): AgentPolicyService
    {
        if ($this->agentPolicyService === null) {
            try {
                $this->agentPolicyService = app(AgentPolicyService::class);
            } catch (\Throwable $e) {
                $this->agentPolicyService = new AgentPolicyService();
            }
        }

        return $this->agentPolicyService;
    }

    protected function getAgentToolExecutor(): AgentToolExecutor
    {
        if ($this->agentToolExecutor === null) {
            try {
                $this->agentToolExecutor = app(AgentToolExecutor::class);
            } catch (\Throwable $e) {
                $this->agentToolExecutor = new AgentToolExecutor(
                    new Handlers\AgentReasoningLoop($this->ai),
                    new Handlers\AgentToolHandler()
                );
            }
        }

        return $this->agentToolExecutor;
    }

    protected function getNextStepSuggestionService(): NextStepSuggestionService
    {
        if ($this->nextStepSuggestionService === null) {
            try {
                $this->nextStepSuggestionService = app(NextStepSuggestionService::class);
            } catch (\Throwable $e) {
                $this->nextStepSuggestionService = new NextStepSuggestionService();
            }
        }

        return $this->nextStepSuggestionService;
    }

    protected function getResourceDiscovery(): OrchestratorResourceDiscovery
    {
        if ($this->resourceDiscovery === null) {
            try {
                $this->resourceDiscovery = app(OrchestratorResourceDiscovery::class);
            } catch (\Throwable $e) {
                $this->resourceDiscovery = new OrchestratorResourceDiscovery(
                    $this->nodeRegistry,
                    $this->collectorRegistry
                );
            }
        }

        return $this->resourceDiscovery;
    }

    protected function getResponseFormatter(): OrchestratorResponseFormatter
    {
        if ($this->responseFormatter === null) {
            try {
                $this->responseFormatter = app(OrchestratorResponseFormatter::class);
            } catch (\Throwable $e) {
                $this->responseFormatter = new OrchestratorResponseFormatter();
            }
        }

        return $this->responseFormatter;
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

        // RULE 1: Active session? Continue it (no AI needed)
        if ($context->has('autonomous_collector')) {
            Log::channel('ai-engine')->info('Continuing active collector session');
            return $this->continueCollector($message, $context, $options);
        }

        if ($context->has('routed_to_node')) {
            $routingDecision = $this->getRoutedSessionPolicyService()->evaluate($message, $context);
            $routingAction = $routingDecision['action'] ?? RoutedSessionPolicyService::DECISION_LOCAL;

            if ($routingAction === RoutedSessionPolicyService::DECISION_CONTINUE) {
                Log::channel('ai-engine')->info('Continuing routed node session', [
                    'node' => $routingDecision['node_slug'],
                    'reason' => $routingDecision['reason'] ?? '',
                ]);
                return $this->continueNode($message, $context, $options);
            }

            if ($routingAction === RoutedSessionPolicyService::DECISION_RE_ROUTE) {
                $targetSlug = $routingDecision['node_slug'];
                Log::channel('ai-engine')->info('Re-routing to different node', [
                    'previous_node' => $context->get('routed_to_node')['node_slug'] ?? 'unknown',
                    'target_node' => $targetSlug,
                    'reason' => $routingDecision['reason'] ?? '',
                ]);

                // Resolve the new target node and forward directly
                $targetNode = $this->getNodeRoutingCoordinator()->resolveNodeForRouting($targetSlug);
                if ($targetNode) {
                    // Update routing context to the new node
                    $context->set('routed_to_node', [
                        'node_slug' => $targetNode->slug,
                        'node_name' => $targetNode->name,
                    ]);

                    $response = $this->getNodeRoutingCoordinator()->forwardAsAgentResponse(
                        $targetNode, $message, $context, $options
                    );
                    $this->appendAssistantMessageIfNew($context, $response->message);
                    $this->contextManager->save($context);
                    return $response;
                }

                // Target node not found — fall through to askAI
                Log::channel('ai-engine')->warning('Re-route target node not found, falling back to askAI', [
                    'target_slug' => $targetSlug,
                ]);
            }

            // LOCAL or failed RE_ROUTE — clear routing and let askAI handle
            Log::channel('ai-engine')->info('Clearing routed node session', [
                'previous_node' => $context->get('routed_to_node')['node_slug'] ?? 'unknown',
                'decision' => $routingAction,
                'reason' => $routingDecision['reason'] ?? '',
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

            $errorMessage = $this->getAgentPolicyService()->noCollectorMatchMessage($message);

            return AgentResponse::conversational(
                message: $errorMessage,
                context: $context
            );
        }

        $classification = null;
        if ($this->hasEntityListContext($context)) {
            $classification = $this->getFollowUpDecisionAIService()->classify($message, $context);
            $options['followup_guard_classification'] = $classification;

            Log::channel('ai-engine')->info('Follow-up guard enabled for message', [
                'session_id' => $sessionId,
                'message' => substr($message, 0, 100),
                'classification' => $classification,
            ]);

            // Let AI follow-up classification decide whether positional code path should run.
            if ($this->shouldHandleEntityLookupByCode($classification)
                && $this->detectPositionalReference($message, $context)) {
                Log::channel('ai-engine')->info('Detected positional reference to previous list');
                $position = $context->metadata['pending_positional_reference'] ?? null;

                return $this->handlePositionalReference(
                    $message,
                    $context,
                    $options,
                    is_int($position) ? $position : null
                );
            }
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
        return $this->getRoutedSessionPolicyService()->shouldContinue($message, $context);
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

        $node = $this->getNodeRoutingCoordinator()->resolveNodeForRouting($nodeSlug);

        if (!$node) {
            Log::channel('ai-engine')->warning('Unable to continue routed session: node not found', [
                'requested_node' => $nodeSlug,
                'session_id' => $context->sessionId,
            ]);
            $context->forget('routed_to_node');
            return $this->askAI($message, $context, $options);
        }

        $response = $this->getNodeRoutingCoordinator()->forwardAsAgentResponse(
            $node,
            $message,
            $context,
            $options
        );

        $this->appendAssistantMessageIfNew($context, $response->message);
        $this->contextManager->save($context);

        return $response;
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
            $decision = $this->applyFollowUpDecisionGuard($decision, $message, $context, $options);

            Log::channel('ai-engine')->info('AI orchestration decision', [
                'message' => substr($message, 0, 100),
                'action' => $decision['action'],
                'resource' => $decision['resource_name'],
                'reason' => substr($decision['reason'] ?? '', 0, 100),
            ]);

            $response = $this->execute($decision, $message, $context, $options);

            // Attach suggested next actions to the response
            $response = $this->attachNextStepSuggestions(
                $response,
                $decision['action'],
                $decision['resource_name'] ?? null,
                $resources,
                $context
            );

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
        return $this->getResourceDiscovery()->discover($options);
    }

    protected function discoverModelConfigs(): array
    {
        return $this->getResourceDiscovery()->discoverModelConfigs();
    }

    protected function getUserProfile(?string $userId): string
    {
        if (!$userId) {
            return "- No user profile available";
        }

        try {
            $userModel = config('ai-engine.user_model');
            if (!$userModel || !class_exists($userModel)) {
                return "- User ID: {$userId}";
            }

            // Fetch user from database
            $user = $userModel::find($userId);

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

    /**
     * Attach contextual next-step suggestions to the response metadata.
     *
     * Suggestions are generated by NextStepSuggestionService based on:
     * - What action was just executed
     * - What entities are in context
     * - What tools/collectors/nodes are available
     *
     * The frontend can render these as clickable chips or text hints.
     */
    protected function attachNextStepSuggestions(
        AgentResponse $response,
        string $lastAction,
        ?string $lastResource,
        array $resources,
        UnifiedActionContext $context
    ): AgentResponse {
        if (!config('ai-agent.next_step.enabled', true)) {
            return $response;
        }

        try {
            $suggestions = $this->getNextStepSuggestionService()->suggest(
                $context,
                $lastAction,
                $lastResource,
                $resources
            );

            if (!empty($suggestions)) {
                $response->metadata = array_merge($response->metadata ?? [], [
                    'suggested_next_actions' => $suggestions,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->debug('NextStepSuggestionService failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    protected function buildPrompt(
        string $message,
        array $resources,
        UnifiedActionContext $context
    ): string {
        return $this->getOrchestratorPromptBuilder()->build($message, $resources, $context);
    }

    protected function formatHistory(UnifiedActionContext $context): string
    {
        return $this->getResponseFormatter()->formatHistory($context);
    }

    protected function formatSelectedEntityContext(UnifiedActionContext $context): string
    {
        $selected = $this->getSelectedEntityContext($context);
        return $this->getResponseFormatter()->formatSelectedEntityContext($selected);
    }

    protected function getSelectedEntityContext(UnifiedActionContext $context): ?array
    {
        return $this->getFollowUpStateService()->getSelectedEntityContext($context);
    }

    protected function formatEntityMetadata(UnifiedActionContext $context): string
    {
        return $this->getResponseFormatter()->formatEntityMetadata($context);
    }

    protected function formatPausedSessions(array $sessions): string
    {
        return $this->getResponseFormatter()->formatPausedSessions($sessions);
    }

    protected function formatCollectors(array $collectors): string
    {
        return $this->getResponseFormatter()->formatCollectors($collectors);
    }

    protected function formatTools(array $tools): string
    {
        return $this->getResponseFormatter()->formatTools($tools);
    }

    protected function formatNodes(array $nodes): string
    {
        return $this->getResponseFormatter()->formatNodes($nodes);
    }

    protected function parseDecision(string $response, array $resources): array
    {
        unset($resources);
        return $this->getOrchestratorDecisionParser()->parse($response);
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

            case 'use_agent_tool':
                return $this->executeAgentTool($decision, $message, $context, $options);

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
                message: $this->getAgentPolicyService()->toolNotSpecifiedMessage(),
                context: $context
            );
        }

        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();
        $selectedEntity = $this->getSelectedEntityContext($context);
        $toolResponse = $this->getToolExecutionCoordinator()->execute(
            (string) $toolName,
            $message,
            $context,
            $modelConfigs,
            $selectedEntity
        );
        if ($toolResponse instanceof AgentResponse) {
            return $toolResponse;
        }

        // Tool not found - fallback to RAG to handle it
        Log::channel('ai-engine')->warning('Tool not found in configs, routing to RAG', [
            'tool_name' => $toolName,
        ]);

        return $this->executeSearchRAG($message, $context, $options);
    }

    protected function executeAgentTool(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        Log::channel('ai-engine')->info('MinimalAIOrchestrator: executeAgentTool called', [
            'message' => substr($message, 0, 100),
            'resource' => $decision['resource_name'] ?? null,
        ]);

        $modelConfigs = $options['model_configs'] ?? $this->discoverModelConfigs();
        $selectedEntity = $this->getSelectedEntityContext($context);

        if ($selectedEntity) {
            $options['selected_entity'] = $selectedEntity;
        }

        return $this->getAgentToolExecutor()->execute(
            $message,
            $modelConfigs,
            $context,
            $options
        );
    }

    protected function executeStartCollector(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        return $this->getCollectorExecutionCoordinator()->execute(
            $decision['resource_name'] ?? null,
            $message,
            $context,
            $options,
            function (array $routeDecision, string $routeMessage, UnifiedActionContext $routeContext, array $routeOptions): AgentResponse {
                return $this->executeRouteToNode($routeDecision, $routeMessage, $routeContext, $routeOptions);
            }
        );
    }

    protected function executeResumeSession(
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $sessionStack = $context->get('session_stack', []);

        if (empty($sessionStack)) {
            return AgentResponse::conversational(
                message: $this->getAgentPolicyService()->noPausedSessionMessage(),
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
        $resumeMessage = $this->getAgentPolicyService()->resumeSessionMessage((string) $collectorName);

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
        return $this->getNodeRoutingCoordinator()->routeDecision(
            $decision,
            $message,
            $context,
            $options,
            function (string $fallbackMessage, UnifiedActionContext $fallbackContext, array $fallbackOptions): AgentResponse {
                return $this->executeSearchRAG($fallbackMessage, $fallbackContext, $fallbackOptions);
            }
        );
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
            $rawResponse = $result['response'] ?? $this->getAgentPolicyService()->ragNoResultsMessage();

            // Store metadata
            $context->metadata['tool_used'] = $result['tool'] ?? 'unknown';
            $context->metadata['fast_path'] = $result['fast_path'] ?? false;
            $ragMetadata = (!empty($result['metadata']) && is_array($result['metadata']))
                ? $result['metadata']
                : [];
            if (!empty($ragMetadata)) {
                $context->metadata['rag_last_metadata'] = $ragMetadata;
            }
            if (!empty($ragMetadata['last_entity_list']) && is_array($ragMetadata['last_entity_list'])) {
                $context->metadata['last_entity_list'] = $ragMetadata['last_entity_list'];
            }
            if (!empty($result['suggested_actions']) && is_array($result['suggested_actions'])) {
                $context->metadata['suggested_actions'] = array_values($result['suggested_actions']);
            } else {
                unset($context->metadata['suggested_actions']);
            }
            // Extract entity metadata for conversation history
            $messageMetadata = [];
            $entityIds = $ragMetadata['entity_ids'] ?? $result['entity_ids'] ?? null;
            if (is_array($entityIds) && !empty($entityIds)) {
                $messageMetadata['entity_ids'] = array_values($entityIds);
                $messageMetadata['entity_type'] = (string) ($ragMetadata['entity_type'] ?? $result['entity_type'] ?? 'item');
            }

            // Pass raw RAG data through AI for summarization
            $responseText = $this->summarizeRAGResponse($message, $rawResponse, $context, $options);

            return AgentResponse::conversational(
                message: $responseText,
                context: $context,
                metadata: $messageMetadata
            );
        }

        // Fallback to simple response
        return AgentResponse::conversational(
            message: $this->getAgentPolicyService()->ragNoRelevantInfoMessage(),
            context: $context
        );
    }

    protected function summarizeRAGResponse(
        string $userMessage,
        string $rawData,
        UnifiedActionContext $context,
        array $options
    ): string {
        if (empty(trim($rawData))) {
            return $rawData;
        }

        try {
            $conversationHistory = $context->conversationHistory ?? [];
            $historyText = '';
            foreach (array_slice($conversationHistory, -3) as $msg) {
                $historyText .= "{$msg['role']}: {$msg['content']}\n";
            }

            $promptTemplate = (string) config('ai-agent.rag_summarization.prompt_template', <<<PROMPT
You are a helpful AI assistant. The user asked a question and the system retrieved the following data.

IMPORTANT RULES:
- When the data contains a LIST of items, you MUST present EACH item with its key details (subject, date, status, sender, amount, etc.).
- Use numbered lists to present multiple items. Include all important fields for each item.
- Do NOT collapse a list into just a count like "you have 5 emails". Always show the individual items with details.
- Do not invent data that is not present in the retrieved data.
- Format the response in a clear, readable way using markdown.

Recent conversation:
:history

User question: :message

Retrieved data:
:data

Present the retrieved data above in full detail, responding naturally to the user's question.
PROMPT);

            $prompt = strtr($promptTemplate, [
                ':history' => $historyText,
                ':message' => $userMessage,
                ':data' => $rawData,
            ]);

            $engine = (string) ($options['engine'] ?? config('ai-agent.rag_summarization.engine', config('ai-engine.default', 'openai')));
            $model = (string) ($options['model'] ?? config('ai-agent.rag_summarization.model', config('ai-engine.orchestration_model', 'gpt-4o-mini')));
            $maxTokens = (int) config('ai-agent.rag_summarization.max_tokens', 1500);
            $temperature = (float) config('ai-agent.rag_summarization.temperature', 0.4);

            $aiResponse = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from($engine),
                model: EntityEnum::from($model),
                maxTokens: $maxTokens,
                temperature: $temperature,
            ));

            $summarized = $aiResponse->getContent();

            Log::channel('ai-engine')->debug('RAG response summarized by AI', [
                'session_id' => $context->sessionId,
                'raw_length' => strlen($rawData),
                'summarized_length' => strlen($summarized),
            ]);

            return $summarized;
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('RAG summarization failed, returning raw response', [
                'error' => $e->getMessage(),
            ]);

            return $rawData;
        }
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
        $followUpContext = $this->formatFollowUpEntityListContext($context);
        $instructions = (array) config('ai-agent.conversational.instructions', [
            'If the user asks a follow-up question about previously listed items, answer directly from context.',
            'Do not repeat the full list unless the user explicitly asks to list/search again.',
            'Respond in a friendly, helpful manner.',
        ]);
        $instructionsText = implode("\n", array_map(fn ($line) => '- ' . trim((string) $line), $instructions));
        $promptTemplate = (string) config('ai-agent.conversational.prompt_template', <<<PROMPT
You are a helpful AI assistant. Respond naturally to the user's message.

Recent conversation:
:history

Recent entity list context:
:entity_context

User: :message

Behavior rules:
:instructions
PROMPT);
        $prompt = strtr($promptTemplate, [
            ':history' => $historyText,
            ':entity_context' => $followUpContext,
            ':message' => $message,
            ':instructions' => $instructionsText,
        ]);

        $engine = (string) ($options['engine'] ?? config('ai-agent.conversational.engine', config('ai-engine.default', 'openai')));
        $model = (string) ($options['model'] ?? config('ai-agent.conversational.model', config('ai-engine.orchestration_model', 'gpt-4o-mini')));
        $maxTokens = (int) config('ai-agent.conversational.max_tokens', 200);
        $temperature = (float) config('ai-agent.conversational.temperature', 0.7);

        $aiResponse = $aiEngine->generate(new AIRequest(
            prompt: $prompt,
            engine: EngineEnum::from($engine),
            model: EntityEnum::from($model),
            maxTokens: $maxTokens,
            temperature: $temperature,
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
     * Detect if user is making a positional reference to a previous list
     */
    protected function detectPositionalReference(string $message, UnifiedActionContext $context): bool
    {
        $position = $this->getPositionalReferenceAIService()->resolvePosition($message, $context);
        if ($position === null) {
            unset($context->metadata['pending_positional_reference']);
            return false;
        }

        $context->metadata['pending_positional_reference'] = $position;

        return true;
    }

    protected function hasEntityListContext(UnifiedActionContext $context): bool
    {
        return $this->getFollowUpStateService()->hasEntityListContext($context);
    }

    protected function shouldHandleEntityLookupByCode(?string $classification): bool
    {
        if (!is_string($classification) || trim($classification) === '') {
            return false;
        }

        return $this->getFollowUpDecisionAIService()->isEntityLookupClassification($classification);
    }

    protected function applyFollowUpDecisionGuard(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): array {
        $updatedDecision = $this->getFollowUpDecisionAIService()->applyGuard(
            $decision,
            $message,
            $context,
            $options
        );

        if (($decision['action'] ?? null) !== ($updatedDecision['action'] ?? null)) {
            Log::channel('ai-engine')->info('Follow-up guard replaced action', [
                'session_id' => $context->sessionId,
                'message' => substr($message, 0, 100),
                'from_action' => $decision['action'] ?? null,
                'to_action' => $updatedDecision['action'] ?? null,
            ]);
        }

        return $updatedDecision;
    }

    protected function formatFollowUpEntityListContext(UnifiedActionContext $context): string
    {
        return $this->getFollowUpStateService()->formatEntityListContext($context);
    }

    /**
     * Handle positional reference by fetching full entity details
     */
    protected function handlePositionalReference(
        string $message,
        UnifiedActionContext $context,
        array $options,
        ?int $preResolvedPosition = null
    ): AgentResponse {
        unset($context->metadata['pending_positional_reference']);

        return $this->getPositionalReferenceCoordinator()->handle(
            $message,
            $context,
            $options,
            $preResolvedPosition,
            function (string $askMessage, UnifiedActionContext $askContext, array $askOptions): AgentResponse {
                return $this->askAI($askMessage, $askContext, $askOptions);
            }
        );
    }

}
