<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;

class NodeSessionManager
{
    public function __construct(
        protected AIEngineService $ai,
        protected NodeRegistryService $nodeRegistry,
        protected NodeRouterService $nodeRouter,
        protected AgentResponseFinalizer $responseFinalizer
    ) {
    }

    public function shouldContinueSession(string $message, UnifiedActionContext $context): bool
    {
        $nodeInfo = $context->get('routed_to_node');
        $nodeSlug = $nodeInfo['node_slug'] ?? null;

        if (!$nodeSlug) {
            return false;
        }

        $node = $this->nodeRegistry->getNode($nodeSlug);
        if (!$node) {
            return false;
        }

        $messageLower = strtolower($message);
        if (preg_match('/\b(email|emails|mail|mails|inbox|message|messages)\b/', $messageLower)) {
            $nodeCollectionsStr = strtolower(json_encode($node->collections ?? []));
            if (!str_contains($nodeCollectionsStr, 'email') && !str_contains($nodeCollectionsStr, 'mail')) {
                Log::channel('ai-engine')->info('Explicit context shift detected (email query on non-email node)', [
                    'node' => $nodeSlug,
                    'message' => substr($message, 0, 100),
                ]);

                return false;
            }
        }

        if ($this->hasActiveRemotePendingAction($context, (string) $nodeSlug)) {
            if ($this->isExplicitNewTaskMessage($message)) {
                Log::channel('ai-engine')->info('Remote pending action paused due to explicit new task', [
                    'node' => $nodeSlug,
                    'message' => substr($message, 0, 100),
                ]);

                return false;
            }

            Log::channel('ai-engine')->info('Keeping routed node session because remote action is pending', [
                'node' => $nodeSlug,
                'message' => substr($message, 0, 100),
            ]);

            return true;
        }

        // Keep routed sessions sticky for direct answers to the previous assistant question.
        if ($this->isLikelyAnswerToPreviousQuestion($message, $context)) {
            Log::channel('ai-engine')->info('Keeping routed node session for follow-up answer', [
                'node' => $nodeSlug,
                'message' => substr($message, 0, 100),
            ]);

            return true;
        }

        $conversationHistory = $context->conversationHistory ?? [];
        $recentMessages = array_slice($conversationHistory, -3);
        $historyText = '';
        foreach ($recentMessages as $msg) {
            $historyText .= "{$msg['role']}: {$msg['content']}\n";
        }

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
2. DIFFERENT: The message is about a completely different domain that the active node does NOT handle

Important:
- Clarifications, confirmations, corrections, and short follow-up answers are usually RELATED.
- Respond DIFFERENT only when the user is clearly starting a new, unrelated task/domain.
- If uncertain, choose RELATED.

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

            return !str_contains($decision, 'DIFFERENT');
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI context detection failed, defaulting to continue routing', [
                'error' => $e->getMessage(),
                'node' => $nodeSlug,
            ]);

            return true;
        }
    }

    public function continueSession(string $message, UnifiedActionContext $context, array $options): ?AgentResponse
    {
        $nodeInfo = $context->get('routed_to_node');
        $nodeSlug = $nodeInfo['node_slug'] ?? null;

        if (!$nodeSlug) {
            return null;
        }

        $node = $this->resolveNodeForRouting($nodeSlug);
        if (!$node) {
            Log::channel('ai-engine')->warning('Unable to continue routed session: node not found', [
                'requested_node' => $nodeSlug,
                'session_id' => $context->sessionId,
            ]);
            $context->forget('routed_to_node');

            return null;
        }

        $response = $this->nodeRouter->forwardChat(
            $node,
            $message,
            $context->sessionId,
            $this->buildForwardOptions($this->attachSelectedEntityToForwardOptions($context, $options)),
            $context->userId
        );

        if ($response['success']) {
            $this->syncRemotePendingAction($context, $node, $response);
            $agentResponse = $this->buildAgentResponseFromRoutedSuccess($response, $context);

            $this->responseFinalizer->persistMessage($context, $response['response']);

            return $agentResponse;
        }

        Log::channel('ai-engine')->warning('Continue routed session failed', [
            'node' => $node->slug,
            'error' => $response['error'] ?? 'Unknown error',
            'session_id' => $context->sessionId,
        ]);

        $failureMessage = $this->formatRoutingFailureMessage($node->slug, $node->url, $response['error'] ?? null);
        $this->responseFinalizer->persistMessage($context, $failureMessage);

        return AgentResponse::failure(
            message: $failureMessage,
            data: $response,
            context: $context
        );
    }

    public function routeToNode(
        string $requestedResource,
        string $message,
        UnifiedActionContext $context,
        array $options
    ): AgentResponse {
        $requestedResource = trim($requestedResource);
        if ($requestedResource === '' || $requestedResource === 'local') {
            return AgentResponse::failure(
                message: $this->translate(
                    'ai-engine::messages.agent.node_no_remote_specified',
                    'No remote node specified.'
                ),
                context: $context
            );
        }

        $node = $this->resolveNodeForRouting($requestedResource);
        if (!$node) {
            Log::channel('ai-engine')->warning('route_to_node could not resolve target node', [
                'resource_name' => $requestedResource,
                'session_id' => $context->sessionId,
            ]);

            return AgentResponse::failure(
                message: $this->translate(
                    'ai-engine::messages.agent.node_matching_remote_not_found',
                    "I couldn't find a remote node matching '{$requestedResource}'.",
                    ['resource' => $requestedResource]
                ),
                context: $context
            );
        }

        $response = $this->nodeRouter->forwardChat(
            $node,
            $message,
            $context->sessionId,
            $this->buildForwardOptions($this->attachSelectedEntityToForwardOptions($context, $options)),
            $context->userId
        );

        if ($response['success']) {
            $this->syncRemotePendingAction($context, $node, $response);
            $context->set('routed_to_node', [
                'node_slug' => $node->slug,
                'node_name' => $node->name,
            ]);

            return $this->buildAgentResponseFromRoutedSuccess($response, $context);
        }

        Log::channel('ai-engine')->warning('route_to_node failed', [
            'requested_resource' => $requestedResource,
            'resolved_node' => $node->slug,
            'node_url' => $node->url,
            'error' => $response['error'] ?? 'Unknown error',
            'session_id' => $context->sessionId,
        ]);

        return AgentResponse::failure(
            message: $this->formatRoutingFailureMessage($node->slug, $node->url, $response['error'] ?? null),
            data: $response,
            context: $context
        );
    }

    protected function buildForwardOptions(array $options): array
    {
        $userToken = request()->bearerToken();
        $forwardHeaders = \LaravelAIEngine\Services\Node\NodeHttpClient::extractForwardableHeaders();
        $locale = (string) (request()->attributes->get('ai_engine_locale') ?: app()->getLocale());
        $localeHeader = $locale !== '' ? ['X-Locale' => $locale] : [];

        $options['headers'] = array_merge($forwardHeaders, $localeHeader, [
            'X-Forwarded-From-Node' => config('app.name'),
            'X-User-Token' => $userToken,
        ]);
        $options['user_token'] = $userToken;

        return $options;
    }

    protected function attachSelectedEntityToForwardOptions(UnifiedActionContext $context, array $options): array
    {
        if (!empty($options['selected_entity']) && is_array($options['selected_entity'])) {
            return $options;
        }

        $selected = $context->metadata['selected_entity_context'] ?? null;
        if (!is_array($selected)) {
            return $options;
        }

        $entityId = isset($selected['entity_id']) ? (int) $selected['entity_id'] : 0;
        $entityType = isset($selected['entity_type']) ? trim((string) $selected['entity_type']) : '';
        if ($entityId <= 0 || $entityType === '') {
            return $options;
        }

        $options['selected_entity'] = [
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'model_class' => $selected['model_class'] ?? null,
            'source_node' => $selected['source_node'] ?? null,
        ];

        return $options;
    }

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

        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/', '', $resourceName));
        $nodes = $this->nodeRegistry->getAllNodes();

        $matchedNode = $nodes->first(function ($candidate) use ($normalized) {
            $slug = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) $candidate->slug));
            $name = strtolower((string) preg_replace('/[^a-z0-9]/', '', (string) $candidate->name));

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
            return $this->nodeRegistry->findNodeForCollection($singular);
        }

        return null;
    }

    protected function formatRoutingFailureMessage(string $nodeSlug, ?string $nodeUrl, ?string $error = null): string
    {
        $summary = $error ? preg_replace('/\s+/', ' ', trim($error)) : 'unknown routing error';
        if (is_string($summary) && strlen($summary) > 220) {
            $summary = substr($summary, 0, 220) . '...';
        }

        $nodeLocation = $nodeUrl ? " at {$nodeUrl}" : '';

        return $this->translate(
            'ai-engine::messages.agent.node_unreachable',
            "I couldn't reach remote node '{$nodeSlug}'{$nodeLocation} ({$summary}). Please verify the node is running and try again.",
            [
                'node' => $nodeSlug,
                'location' => $nodeLocation,
                'summary' => $summary,
            ]
        );
    }

    protected function translate(string $key, string $fallback, array $replace = []): string
    {
        $translated = __($key, $replace);

        if (!is_string($translated) || $translated === $key) {
            $fallbackText = $fallback;
            foreach ($replace as $replaceKey => $replaceValue) {
                $fallbackText = str_replace(":{$replaceKey}", (string) $replaceValue, $fallbackText);
            }

            return $fallbackText;
        }

        return $translated;
    }

    protected function buildAgentResponseFromRoutedSuccess(array $response, UnifiedActionContext $context): AgentResponse
    {
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        $needsUserInput = $this->isRemoteAwaitingInput($metadata);

        return new AgentResponse(
            success: true,
            message: (string) ($response['response'] ?? ''),
            data: $response,
            context: $context,
            needsUserInput: $needsUserInput,
            metadata: $metadata,
            isComplete: !$needsUserInput
        );
    }

    protected function syncRemotePendingAction(UnifiedActionContext $context, \LaravelAIEngine\Models\AINode $node, array $response): void
    {
        $metadata = is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
        if (!$this->isRemoteAwaitingInput($metadata)) {
            $this->clearRemotePendingAction($context);

            return;
        }

        $message = (string) ($response['response'] ?? '');
        $pending = [
            'type' => 'remote_node_session',
            'status' => 'awaiting_input',
            'node_slug' => (string) $node->slug,
            'node_name' => (string) ($node->name ?? $node->slug),
            'current_step' => $metadata['current_step'] ?? null,
            'pending_entity_slot' => $this->extractPendingEntitySlot($message),
            'updated_at' => now()->toIso8601String(),
        ];

        $context->set('remote_pending_action', $pending);
        $context->pendingAction = [
            'id' => 'remote:' . (string) $node->slug,
            'type' => 'remote_node_session',
            'label' => 'Remote node session',
            'description' => 'Awaiting user input to continue remote node session',
            'data' => $pending,
        ];
    }

    protected function clearRemotePendingAction(UnifiedActionContext $context): void
    {
        $context->forget('remote_pending_action');

        if (is_array($context->pendingAction) && ($context->pendingAction['type'] ?? null) === 'remote_node_session') {
            $context->pendingAction = null;
        }
    }

    protected function isRemoteAwaitingInput(array $metadata): bool
    {
        if (array_key_exists('needs_user_input', $metadata)) {
            return (bool) $metadata['needs_user_input'];
        }

        if (array_key_exists('session_active', $metadata)) {
            return (bool) $metadata['session_active'];
        }

        if (array_key_exists('session_completed', $metadata)) {
            return !((bool) $metadata['session_completed']);
        }

        return false;
    }

    protected function hasActiveRemotePendingAction(UnifiedActionContext $context, string $nodeSlug): bool
    {
        $pending = $context->get('remote_pending_action');
        if (!is_array($pending)) {
            return false;
        }

        if (($pending['status'] ?? null) !== 'awaiting_input') {
            return false;
        }

        $pendingNode = (string) ($pending['node_slug'] ?? '');
        if ($pendingNode === '') {
            return true;
        }

        return $pendingNode === $nodeSlug;
    }

    protected function isExplicitNewTaskMessage(string $message): bool
    {
        $trimmed = trim($message);
        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match('/^(list|show|search|find|get|count|create|new|update|delete|open|help)\b/i', strtolower($trimmed));
    }

    protected function isLikelyAnswerToPreviousQuestion(string $message, UnifiedActionContext $context): bool
    {
        $trimmedMessage = trim($message);
        if ($trimmedMessage === '' || mb_strlen($trimmedMessage) > 180) {
            return false;
        }

        $history = $context->conversationHistory ?? [];
        $lastAssistant = '';
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if (($history[$i]['role'] ?? null) === 'assistant') {
                $lastAssistant = (string) ($history[$i]['content'] ?? '');
                break;
            }
        }

        if ($lastAssistant === '') {
            return false;
        }

        $assistantLower = strtolower($lastAssistant);
        $assistantAskedQuestion = str_contains($lastAssistant, '?')
            || str_contains($assistantLower, 'please')
            || str_contains($assistantLower, 'confirm')
            || str_contains($assistantLower, 'specify')
            || str_contains($assistantLower, 'proceed');

        if (!$assistantAskedQuestion) {
            return false;
        }

        // Fresh commands usually indicate topic shift.
        if (preg_match('/^(list|show|search|find|get|what|who|when|where|why|how|open|help)\b/i', strtolower($trimmedMessage))) {
            return false;
        }

        return true;
    }

    protected function extractPendingEntitySlot(string $message): ?array
    {
        $lower = strtolower($message);
        $entity = null;
        $candidates = array_values(array_filter(
            array_map(static fn (mixed $term): string => mb_strtolower(trim((string) $term)), (array) config('ai-agent.routing_classifier.pending_entity_terms', ['record', 'item', 'entry'])),
            static fn (string $term): bool => $term !== ''
        ));

        foreach ($candidates as $candidate) {
            if (str_contains($lower, $candidate)) {
                $entity = $candidate;
                break;
            }
        }

        $value = null;
        if (preg_match_all('/["“]([^"”\n]{1,120})["”]/u', $message, $matches) && !empty($matches[1])) {
            $candidate = trim((string) end($matches[1]));
            if ($candidate !== '') {
                $value = $candidate;
            }
        }

        if ($entity === null && $value === null) {
            return null;
        }

        return [
            'entity' => $entity ?: 'item',
            'value' => $value,
        ];
    }
}
