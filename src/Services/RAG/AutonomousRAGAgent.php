<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Services\AIEngineManager;

/**
 * Autonomous RAG Agent — Slim Orchestrator
 *
 * Coordinates AI-driven tool selection and execution for data queries.
 * All heavy lifting is delegated to focused services:
 *
 *  - RAGModelDiscovery      → model/config resolution, available models metadata
 *  - RAGToolDispatcher      → tool execution dispatch, unified node-routing signal
 *  - RAGQueryExecutor       → DB queries, pagination, aggregation (used by dispatcher)
 *  - RAGFilterService       → filter application, ID resolution (used by executor)
 *  - AutonomousRAGDecisionService → AI prompt + decision parsing
 *
 * This class is responsible ONLY for:
 *  1. Building AI context from discovered models/nodes
 *  2. Choosing between function-calling vs prompt-based routing
 *  3. Delegating execution to RAGToolDispatcher
 *  4. Returning the result (including node-routing signals) to the caller
 *
 * Node routing is NOT handled here — the caller (MinimalAIOrchestrator)
 * owns that responsibility via NodeRoutingCoordinator.
 */
class AutonomousRAGAgent
{
    public function __construct(
        protected AIEngineManager $ai,
        protected RAGModelDiscovery $modelDiscovery,
        protected RAGToolDispatcher $toolDispatcher,
        protected AutonomousRAGDecisionService $decisionService,
    ) {
    }

    /**
     * Process a message — AI decides which tool to use.
     *
     * Returns a standardised result array. If the result contains
     * `should_route_to_node => true`, the caller (MinimalAIOrchestrator)
     * should hand off to NodeRoutingCoordinator.
     */
    public function process(
        string $message,
        string $sessionId,
        $userId = null,
        array $conversationHistory = [],
        array $options = []
    ): array {
        $startTime = microtime(true);

        // Hydrate last entity list from cache if not already present
        $options = $this->hydrateEntityListFromCache($sessionId, $options);

        // Build AI context from discovered models and nodes
        $context = $this->buildAIContext($message, $conversationHistory, $userId, $options);

        // Choose execution path: function-calling (OpenAI) or prompt-based routing
        $model = $options['model'] ?? config('ai-agent.autonomous_rag.default_model', 'gpt-4o-mini');

        if ($this->decisionService->shouldUseFunctionCalling($model, $options)) {
            return $this->processWithFunctionCalling(
                $message, $sessionId, $userId, $conversationHistory, $context, $options, $model
            );
        }

        // Prompt-based routing
        $decision = $this->decisionService->decide($message, $context, $model, $options);

        Log::channel('ai-engine')->info('AutonomousRAGAgent: AI decision', [
            'session_id' => $sessionId,
            'tool' => $decision['tool'] ?? 'unknown',
            'reasoning' => $decision['reasoning'] ?? '',
            'duration_ms' => round((microtime(true) - $startTime) * 1000),
        ]);

        return $this->toolDispatcher->dispatch(
            $decision, $message, $sessionId, $userId, $conversationHistory, $options
        );
    }

    // ──────────────────────────────────────────────
    //  Function-calling path (OpenAI)
    // ──────────────────────────────────────────────

    protected function processWithFunctionCalling(
        string $message,
        string $sessionId,
        $userId,
        array $conversationHistory,
        array $context,
        array $options,
        string $model
    ): array {
        $startTime = microtime(true);
        $functions = $this->buildFunctionDefinitions($context);
        $messages = $this->buildConversationMessages($message, $conversationHistory, $context);

        try {
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $message,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from($model),
                userId: $userId,
                messages: $messages,
                metadata: ['session_id' => $sessionId],
                functions: $functions,
                functionCall: ['name' => 'auto']
            );

            $response = $this->ai->processRequest($request);
            $content = $response->getContent();
            $functionCall = $response->getFunctionCall();

            if ($functionCall) {
                $functionName = $functionCall['name'] ?? null;
                $functionArgs = json_decode($functionCall['arguments'] ?? '{}', true);

                Log::channel('ai-engine')->info('AutonomousRAGAgent: function call', [
                    'function' => $functionName,
                    'duration_ms' => round((microtime(true) - $startTime) * 1000),
                ]);

                $decision = [
                    'tool' => 'model_tool',
                    'parameters' => [
                        'tool_name' => $functionName,
                        'tool_params' => $functionArgs,
                    ],
                ];

                return $this->toolDispatcher->dispatch(
                    $decision, '', $sessionId, $userId, $conversationHistory, $options
                );
            }

            return [
                'success' => true,
                'response' => $content,
                'tool' => 'direct_response',
                'fast_path' => true,
            ];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Function calling failed, falling back to prompt routing', [
                'error' => $e->getMessage(),
            ]);

            $decision = $this->decisionService->decide($message, $context, $model, $options);

            return $this->toolDispatcher->dispatch(
                $decision, $message, $sessionId, $userId, $conversationHistory, $options
            );
        }
    }

    // ──────────────────────────────────────────────
    //  AI context building
    // ──────────────────────────────────────────────

    protected function buildAIContext(string $message, array $conversationHistory, $userId, array $options): array
    {
        return [
            'conversation' => $this->summarizeConversation($conversationHistory),
            'models' => $this->modelDiscovery->getAvailableModels($options),
            'nodes' => $this->modelDiscovery->getAvailableNodes(),
            'user_id' => $userId,
            'is_master' => config('ai-engine.nodes.is_master', true),
            'last_entity_list' => $options['last_entity_list'] ?? null,
            'selected_entity' => $options['selected_entity'] ?? null,
        ];
    }

    protected function summarizeConversation(array $history): string
    {
        if (empty($history)) {
            return 'No previous conversation.';
        }

        $summary = "Recent conversation:\n";
        $window = (int) config('ai-agent.autonomous_rag.conversation_history_window', 6);
        $maxLen = (int) config('ai-agent.autonomous_rag.conversation_truncate_length', 200);

        foreach (array_slice($history, -$window) as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            $content = strlen($content) > $maxLen ? substr($content, 0, $maxLen) . '...' : $content;
            $summary .= "- {$role}: {$content}\n";
        }

        return $summary;
    }

    // ──────────────────────────────────────────────
    //  Function-calling helpers
    // ──────────────────────────────────────────────

    protected function buildFunctionDefinitions(array $context): array
    {
        $functions = [];

        foreach ($context['models'] ?? [] as $model) {
            if (empty($model['tools'])) {
                continue;
            }

            foreach ($model['tools'] as $toolName => $tool) {
                $functions[] = [
                    'name' => $toolName,
                    'description' => $tool['description'] ?? "Tool for {$toolName}",
                    'parameters' => $this->convertToolParametersToJsonSchema($tool['parameters'] ?? []),
                ];
            }
        }

        return $functions;
    }

    protected function convertToolParametersToJsonSchema(array $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $description) {
            $parts = explode(' - ', $description);
            $rules = explode('|', $parts[0] ?? '');
            $desc = $parts[1] ?? '';

            $isRequired = in_array('required', $rules);
            $type = 'string';

            foreach ($rules as $rule) {
                if (in_array($rule, ['string', 'integer', 'number', 'boolean', 'array', 'object'])) {
                    $type = $rule;
                    break;
                }
            }

            $properties[$name] = ['type' => $type, 'description' => $desc];

            if ($isRequired) {
                $required[] = $name;
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    protected function buildConversationMessages(string $message, array $conversationHistory, array $context): array
    {
        $systemPrompt = (string) config(
            'ai-agent.autonomous_rag.function_calling_system_prompt',
            'You are an intelligent assistant with access to tools. Use the available tools to accomplish the user\'s goals.'
        );

        if (!empty($context['last_entity_list']['entity_data'])) {
            $entityType = $context['last_entity_list']['entity_type'] ?? 'item';
            $systemPrompt .= "\n\nPrevious results ({$entityType}):\n" . json_encode($context['last_entity_list']['entity_data'], JSON_PRETTY_PRINT);
        }

        if (!empty($context['selected_entity']) && is_array($context['selected_entity'])) {
            $systemPrompt .= "\n\nCurrent selected entity:\n" . json_encode($context['selected_entity'], JSON_PRETTY_PRINT);
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        foreach ($conversationHistory as $turn) {
            $messages[] = [
                'role' => $turn['role'] ?? 'user',
                'content' => $turn['content'] ?? '',
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $message];

        return $messages;
    }

    // ──────────────────────────────────────────────
    //  Cache hydration
    // ──────────────────────────────────────────────

    protected function hydrateEntityListFromCache(string $sessionId, array $options): array
    {
        if (!$sessionId || isset($options['last_entity_list'])) {
            return $options;
        }

        $queryState = Cache::get("rag_query_state:{$sessionId}");
        if (!$queryState) {
            return $options;
        }

        $options['last_entity_list'] = [
            'entity_type' => $queryState['model'] ?? 'item',
            'entity_data' => $queryState['entity_data'] ?? [],
            'entity_ids' => $queryState['entity_ids'] ?? [],
            'start_position' => $queryState['start_position'] ?? 1,
            'end_position' => $queryState['end_position'] ?? count($queryState['entity_ids'] ?? []),
            'current_page' => $queryState['current_page'] ?? 1,
        ];

        return $options;
    }
}
