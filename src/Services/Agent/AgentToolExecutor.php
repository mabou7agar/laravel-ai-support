<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Handlers\AgentReasoningLoop;
use LaravelAIEngine\Services\Agent\Handlers\AgentToolHandler;
use LaravelAIEngine\Services\Agent\Handlers\CrossNodeToolResolver;

/**
 * Agent-based tool executor — thin coordinator.
 *
 * Delegates to:
 *  - AgentReasoningLoop   → prompt building, AI calls, parsing, loop control
 *  - AgentToolHandler     → local tool registry + execution
 *  - CrossNodeToolResolver → remote tool registry + cross-node execution
 *
 * The agent sees local and remote tools in one flat list. When it calls
 * a tool, this coordinator routes to the correct handler transparently.
 */
class AgentToolExecutor
{
    public function __construct(
        protected AgentReasoningLoop $reasoningLoop,
        protected AgentToolHandler $toolHandler,
        protected ?CrossNodeToolResolver $crossNodeResolver = null
    ) {
    }

    // ──────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────

    /**
     * Run the agent loop for a user message.
     *
     * @param string               $message      User message
     * @param array                $modelConfigs  FQCN list of AutonomousModelConfig subclasses
     * @param UnifiedActionContext $context       Session context
     * @param array                $options       Extra options (selected_entity, etc.)
     * @return AgentResponse
     */
    public function execute(
        string $message,
        array $modelConfigs,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        // 1. Build unified tool registry (local + remote)
        $toolRegistry = $this->buildUnifiedRegistry($modelConfigs);

        if (empty($toolRegistry)) {
            return AgentResponse::failure(
                message: 'No tools available for this operation.',
                context: $context
            );
        }

        // 2. Format for prompt
        $toolSchemaText = $this->toolHandler->formatSchemaForPrompt($toolRegistry);
        $conversationSnippet = $this->buildConversationSnippet($context);
        $selectedEntity = $options['selected_entity'] ?? null;
        $entityContext = $selectedEntity ? json_encode($selectedEntity, JSON_UNESCAPED_UNICODE) : 'none';

        // 3. Run reasoning loop — tool calls are routed via callback
        $result = $this->reasoningLoop->run(
            $message,
            $toolSchemaText,
            $conversationSnippet,
            $entityContext,
            fn(string $toolName, array $params) => $this->dispatchTool($toolName, $params, $toolRegistry, $context, $options)
        );

        // 4. Build response
        return AgentResponse::conversational(
            message: $result['message'],
            context: $context,
            metadata: $result['metadata'] ?? []
        );
    }

    // ──────────────────────────────────────────────
    //  Unified registry
    // ──────────────────────────────────────────────

    /**
     * Merge local tools (from AutonomousModelConfig) with remote tools
     * (from active nodes) into one flat registry.
     *
     * Local tools take precedence on name collision.
     */
    protected function buildUnifiedRegistry(array $modelConfigs): array
    {
        // Local tools first (higher priority)
        $local = $this->toolHandler->buildRegistry($modelConfigs);

        // Remote tools (only if cross-node resolver is available)
        $remote = [];
        if ($this->crossNodeResolver) {
            try {
                $remote = $this->crossNodeResolver->buildRemoteRegistry();
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('AgentToolExecutor: failed building remote registry', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Merge: local wins on collision
        $unified = array_merge($remote, $local);

        Log::channel('ai-engine')->debug('AgentToolExecutor: unified registry', [
            'local_count' => count($local),
            'remote_count' => count($remote),
            'total' => count($unified),
        ]);

        return $unified;
    }

    // ──────────────────────────────────────────────
    //  Tool dispatch
    // ──────────────────────────────────────────────

    /**
     * Route a tool call to the correct handler based on source.
     *
     * @return string Observation text for the reasoning loop
     */
    protected function dispatchTool(
        string $toolName,
        array $params,
        array $toolRegistry,
        UnifiedActionContext $context,
        array $options
    ): string {
        if (!isset($toolRegistry[$toolName])) {
            return "Error: Tool '{$toolName}' not found. Available: " . implode(', ', array_keys($toolRegistry));
        }

        $toolDef = $toolRegistry[$toolName];
        $source = $toolDef['source'] ?? 'local';

        Log::channel('ai-engine')->info('AgentToolExecutor: dispatching tool', [
            'tool' => $toolName,
            'source' => $source,
            'node' => $toolDef['node_slug'] ?? 'local',
        ]);

        if ($source === 'remote') {
            if (!$this->crossNodeResolver) {
                return "Error: Tool '{$toolName}' is on a remote node but cross-node resolution is not available.";
            }
            return $this->crossNodeResolver->execute($toolDef, $params, $context);
        }

        return $this->toolHandler->execute($toolDef, $params, $context, $options);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    protected function buildConversationSnippet(UnifiedActionContext $context): string
    {
        $history = $context->conversationHistory ?? [];
        $recent = array_slice($history, -4);
        $lines = [];
        foreach ($recent as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            if (strlen($content) > 200) {
                $content = substr($content, 0, 200) . '...';
            }
            $lines[] = "{$role}: {$content}";
        }
        return !empty($lines) ? implode("\n", $lines) : '(new conversation)';
    }
}
