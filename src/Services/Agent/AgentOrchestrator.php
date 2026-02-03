<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\MessageHandlerInterface;
use Illuminate\Support\Facades\Log;

class AgentOrchestrator
{
    /** @var MessageHandlerInterface[] */
    protected array $handlers = [];

    public function __construct(
        protected MessageAnalyzer $messageAnalyzer,
        protected ContextManager $contextManager
    ) {}

    /**
     * Register a message handler
     */
    public function registerHandler(MessageHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    public function process(
        string $message,
        string $sessionId,
        $userId,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Agent orchestrator procAgent orchestrator processing message', [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'message_length' => strlen($message),
        ]);



       $context = $this->contextManager->getOrCreate($sessionId, $userId);

        $context->addUserMessage($message);

        // STEP 1: Analyze message to determine routing (including remote node check)
        $analysis = $this->messageAnalyzer->analyze($message, $context);

        Log::channel('ai-engine')->info('Message analyzed', [
            'type' => $analysis['type'],
            'action' => $analysis['action'],
            'confidence' => $analysis['confidence'],
            'reasoning' => $analysis['reasoning'],
            'user_id' => $context->userId,
        ]);

        // STEP 2: Handle remote routing if analysis determined it
        if ($analysis['action'] === 'route_to_remote_node') {
            $isForwarded = $options['is_forwarded'] ?? false;

            Log::channel('ai-engine')->info('Remote routing check', [
                'action' => $analysis['action'],
                'is_forwarded' => $isForwarded,
                'target_node' => $analysis['target_node'] ?? null,
            ]);

            if (!$isForwarded) {
                $remoteResponse = $this->routeToSpecificNode($message, $context, $analysis, $options);
                if ($remoteResponse) {
                    $context->addAssistantMessage($remoteResponse->message);
                    $this->contextManager->save($context);
                    return $remoteResponse;
                }

                Log::channel('ai-engine')->warning('Remote routing failed, falling through to local handlers');
            }
        }

        // STEP 3: Find and execute handler
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($analysis['action'])) {
                // Pass analysis metadata (including crud_operation) to handler
                $handlerOptions = array_merge($options, $analysis);
                $response = $handler->handle($message, $context, $handlerOptions);

                // Add assistant's response to conversation history for persistence
                $context->addAssistantMessage($response->message);

                $this->contextManager->save($context);
                return $response;
            }
        }

        // Fallback: No handler found
        Log::channel('ai-engine')->warning('No handler found for action', [
            'action' => $analysis['action'],
        ]);

        $fallbackMessage = "I'm not sure how to handle that. Can you try rephrasing?";
        $context->addAssistantMessage($fallbackMessage);

        return AgentResponse::conversational(
            message: $fallbackMessage,
            context: $context
        );
    }

    /**
     * Route to a specific node determined by analysis
     */
    protected function routeToSpecificNode(
        string $message,
        \LaravelAIEngine\DTOs\UnifiedActionContext $context,
        array $analysis,
        array $options
    ): ?AgentResponse {
        $targetNode = $analysis['target_node'] ?? null;

        if (!$targetNode || !isset($targetNode['node_id'])) {
            return null;
        }

        try {
            $node = \LaravelAIEngine\Models\AINode::find($targetNode['node_id']);

            if (!$node) {
                Log::channel('ai-engine')->warning('Target node not found', [
                    'node_id' => $targetNode['node_id'],
                ]);
                return null;
            }

            Log::channel('ai-engine')->info('Routing to specific node based on analysis', [
                'node' => $node->name,
                'collector' => $analysis['collector_name'] ?? 'N/A',
                'session_id' => $context->sessionId,
            ]);

            // Get the original bearer token from the request to pass to the node
            // This allows the global CheckAuth middleware on the node to authenticate the user
            $originalToken = request()->bearerToken();

            // Extract forwardable headers from current request
            $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();

            $headers = array_merge($forwardHeaders, [
                'X-Forwarded-From-Node' => config('app.name'),
                'X-User-Token' => $originalToken,
            ]);

            $response = \LaravelAIEngine\Services\Node\NodeHttpClient::makeAuthenticated($node, false, 300, $headers)
                ->timeout(30)
                ->withHeaders([
                    'X-Forwarded-From-Node' => config('app.name'),
                    'X-User-Token' => $originalToken,
                ])
                ->post($node->getApiUrl('chat'), [
                    'message' => $message,
                    'session_id' => $context->sessionId,
                    'user_id' => $context->userId,
                    'token' => $originalToken, // Also pass in body for CheckAuth
                    'options' => [
                        'engine' => $options['engine'] ?? 'openai',
                        'model' => $options['model'] ?? 'gpt-4o-mini',
                        'use_actions' => true,
                        'use_intelligent_rag' => true,
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if (($data['success'] ?? true) && !empty($data['response'])) {
                    $metadata = $data['metadata'] ?? [];
                    $workflowActive = (bool) ($metadata['workflow_active'] ?? false);
                    $workflowCompleted = (bool) ($metadata['workflow_completed'] ?? false);
                    $needsFollowUp = $workflowActive && !$workflowCompleted;

                    Log::channel('ai-engine')->info('Remote node handled request', [
                        'node' => $node->name,
                        'session_id' => $context->sessionId,
                        'needs_follow_up' => $needsFollowUp,
                    ]);

                    $routedNodePayload = [
                        'node_id' => $node->id,
                        'node_slug' => $node->slug,
                        'node_name' => $node->name,
                        'collector_name' => $analysis['collector_name'] ?? null,
                        'locked_at' => now()->toIso8601String(),
                    ];

                    // ALWAYS store the last remote node for follow-up context detection
                    // This allows follow-up questions like "total amount" after "total invoices"
                    $context->set('last_remote_node', $routedNodePayload);
                    
                    // Also store the last discussed entity for context
                    if (!empty($analysis['collector_name'])) {
                        $context->set('last_discussed_entity', $analysis['collector_name']);
                    }
                    
                    Log::channel('ai-engine')->info('Stored last remote node context for follow-ups', [
                        'node' => $node->name,
                        'entity' => $analysis['collector_name'] ?? 'N/A',
                        'session_id' => $context->sessionId,
                    ]);

                    if ($needsFollowUp) {
                        // Continue pinning subsequent turns to this node until it signals completion
                        $context->set('routed_to_node', $routedNodePayload);
                    } else {
                        // Remote flow finished – release node lock for future turns
                        if ($context->has('routed_to_node')) {
                            $context->forget('routed_to_node');
                        }
                    }

                    $agentResponse = new AgentResponse(
                        success: true,
                        message: $data['response'],
                        data: [
                            'source_node' => [
                                'id' => $node->id,
                                'slug' => $node->slug,
                                'name' => $node->name,
                            ],
                            'credits_used' => $data['credits_used'] ?? null,
                        ],
                        strategy: $metadata['agent_strategy'] ?? 'remote_node',
                        context: $context,
                        needsUserInput: $needsFollowUp,
                        actions: null,
                        metadata: array_filter(array_merge($metadata, [
                            'last_remote_node' => $routedNodePayload,
                            'routed_node' => $needsFollowUp ? $routedNodePayload : null,
                            'remote_follow_up_required' => $needsFollowUp,
                            'remote_duration_ms' => $data['duration_ms'] ?? null,
                        ]), fn ($value) => $value !== null),
                        isComplete: !$needsFollowUp
                    );

                    return $agentResponse;
                }
            } else {
                Log::channel('ai-engine')->warning('Remote node request failed', [
                    'node' => $node->name,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 500),
                ]);
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to route to specific node', [
                'node' => $targetNode['node_name'] ?? 'Unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return null;
    }

}
