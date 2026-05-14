<?php

namespace LaravelAIEngine\DTOs;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Enums\EntityState;

class UnifiedActionContext
{
    public array $flowStack = [];

    public function __construct(
        public string $sessionId,
        public $userId = null,
        public array $conversationHistory = [],
        public ?array $pendingAction = null,
        public ?array $dataCollectorState = null,
        public array $extractedData = [],
        public array $validationErrors = [],
        public string $currentStrategy = 'conversational',
        public ?array $intentAnalysis = null,
        public array $metadata = [],
        public ?string $currentFlow = null,
        public ?string $currentStep = null,
        public array $runtimeState = []
    ) {
    }

    public function addUserMessage(string $message): void
    {
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $this->truncateMessage($message),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function addAssistantMessage(string $message, array $metadata = []): void
    {
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $this->truncateMessage($message),
            'metadata' => $metadata,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    protected function truncateMessage(string $message): string
    {
        $limit = $this->conversationMessageLimit();

        return mb_strlen($message) > $limit ? mb_substr($message, 0, $limit) . '...' : $message;
    }

    protected function conversationMessageLimit(): int
    {
        try {
            return max(200, (int) config('ai-agent.context_compaction.max_message_chars', 2000));
        } catch (\Throwable) {
            return 2000;
        }
    }

    public function switchStrategy(string $newStrategy): void
    {
        $oldStrategy = $this->currentStrategy;
        $this->currentStrategy = $newStrategy;

        $this->migrateContext($oldStrategy, $newStrategy);
    }

    protected function migrateContext(string $from, string $to): void
    {
        if ($from === 'quick_action' && $to === 'guided_flow') {
            if ($this->pendingAction && !empty($this->pendingAction['params'])) {
                $this->metadata['initial_data'] = $this->pendingAction['params'];
            }
        }

        if ($from === 'guided_flow' && $to === 'quick_action') {
            if ($this->dataCollectorState) {
                $this->extractedData = array_merge(
                    $this->extractedData,
                    $this->dataCollectorState['data'] ?? []
                );
            }
        }
    }

    public function clearDataCollectorState(): void
    {
        $this->dataCollectorState = null;
    }

    public function set(string $key, $value): void
    {
        $this->runtimeState[$key] = $value;
    }

    /**
     * Set entity state with enum-based categorization
     * 
     * @param string $entity Entity name (e.g., 'customer', 'products')
     * @param EntityState $state State of the entity
     * @param mixed $value Data associated with this state
     */
    public function setEntityState(string $entity, EntityState $state, $value): void
    {
        $key = $state->getKey($entity);
        $this->runtimeState[$key] = $value;

        // Track entity state metadata
        $this->runtimeState["_entity_states"][$entity] = [
            'state' => $state->value,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get entity state value
     * 
     * @param string $entity Entity name
     * @param EntityState $state State to retrieve
     * @param mixed $default Default value if not found
     */
    public function getEntityState(string $entity, EntityState $state, $default = null)
    {
        $key = $state->getKey($entity);
        return $this->runtimeState[$key] ?? $default;
    }

    /**
     * Check if entity has a specific state
     * 
     * @param string $entity Entity name
     * @param EntityState $state State to check
     */
    public function hasEntityState(string $entity, EntityState $state): bool
    {
        $key = $state->getKey($entity);
        return isset($this->runtimeState[$key]);
    }

    /**
     * Get current state of an entity
     * 
     * @param string $entity Entity name
     * @return EntityState|null Current state or null if not tracked
     */
    public function getCurrentEntityState(string $entity): ?EntityState
    {
        $stateValue = $this->runtimeState["_entity_states"][$entity]['state'] ?? null;

        if (!$stateValue) {
            return null;
        }

        return EntityState::from($stateValue);
    }

    public function get(string $key, $default = null)
    {
        return $this->runtimeState[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->runtimeState[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->runtimeState[$key]);
    }

    /**
     * Push current flow onto stack and start a child flow.
     */
    public function pushFlow(string $flow, ?string $step = null, array $state = []): void
    {
        $this->flowStack[] = [
            'flow' => $this->currentFlow,
            'step' => $this->currentStep,
            'state' => $this->runtimeState,
        ];

        $this->currentFlow = $flow;
        $this->currentStep = $step;
        // Merge the new state with existing state (don't replace)
        $this->runtimeState = array_merge($this->runtimeState, $state);

        $this->saveToCache();
    }

    /**
     * Create an isolated child context.
     */
    public function createChildContext(array $initialData = []): self
    {
        $childContext = new self(
            sessionId: $this->sessionId,
            userId: $this->userId,
            conversationHistory: $this->conversationHistory, // Share conversation
            currentStrategy: $this->currentStrategy,
            intentAnalysis: $this->intentAnalysis,
        );

        $childContext->runtimeState = $initialData;

        $childContext->metadata['is_child_flow'] = true;
        $childContext->metadata['parent_flow'] = $this->currentFlow;

        return $childContext;
    }

    /**
     * Merge child result back into parent context.
     */
    public function mergeChildResult(array $result): void
    {
        if (isset($result['entity_id'])) {
            $this->runtimeState['created_entity_id'] = $result['entity_id'];
        }

        if (isset($result['entity'])) {
            $this->runtimeState['created_entity'] = $result['entity'];
        }
    }

    /**
     * Pop flow from stack and restore parent flow.
     */
    public function popFlow(): ?array
    {
        if (empty($this->flowStack)) {
            return null;
        }

        $parent = array_pop($this->flowStack);

        $this->currentFlow = $parent['flow'];
        $this->currentStep = $parent['step'];
        $this->runtimeState = $parent['state'];

        return $parent;
    }

    /**
     * Save context to cache
     */
    public function saveToCache(): void
    {
        $cacheKey = "agent_context:{$this->sessionId}";
        Cache::put($cacheKey, $this->toArray(), now()->addHours(24));
    }

    /**
     * Check if we're in a child flow.
     */
    public function isInChildFlow(): bool
    {
        return !empty($this->flowStack);
    }

    /**
     * Get parent flow info without popping.
     */
    public function getParentFlow(): ?array
    {
        if (empty($this->flowStack)) {
            return null;
        }

        return end($this->flowStack);
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'user_id' => $this->userId,
            'conversation_history' => $this->conversationHistory,
            'pending_action' => $this->pendingAction,
            'data_collector_state' => $this->dataCollectorState,
            'extracted_data' => $this->extractedData,
            'validation_errors' => $this->validationErrors,
            'current_strategy' => $this->currentStrategy,
            'intent_analysis' => $this->intentAnalysis,
            'metadata' => $this->metadata,
            'current_flow' => $this->currentFlow,
            'current_step' => $this->currentStep,
            'runtime_state' => $this->stripClosures($this->runtimeState),
            'flow_stack' => $this->stripClosures($this->flowStack),
        ];
    }

    /**
     * Recursively remove Closures from data to prevent serialization errors
     */
    protected function stripClosures($data)
    {
        if ($data instanceof \Closure) {
            return null;
        }

        if (is_array($data)) {
            $result = [];
            foreach ($data as $key => $value) {
                $stripped = $this->stripClosures($value);
                // Only include non-null values (skip Closures)
                if ($stripped !== null || !($value instanceof \Closure)) {
                    $result[$key] = $stripped;
                }
            }
            return $result;
        }

        if (is_object($data)) {
            // For objects, convert to array first
            $array = (array) $data;
            return $this->stripClosures($array);
        }

        return $data;
    }

    public static function fromArray(array $data): self
    {
        $context = new self(
            sessionId: $data['session_id'],
            userId: $data['user_id'],
            conversationHistory: $data['conversation_history'] ?? [],
            pendingAction: $data['pending_action'] ?? null,
            dataCollectorState: $data['data_collector_state'] ?? null,
            extractedData: $data['extracted_data'] ?? [],
            validationErrors: $data['validation_errors'] ?? [],
            currentStrategy: $data['current_strategy'] ?? 'conversational',
            intentAnalysis: $data['intent_analysis'] ?? null,
            metadata: $data['metadata'] ?? [],
            currentFlow: $data['current_flow'] ?? null,
            currentStep: $data['current_step'] ?? null,
            runtimeState: $data['runtime_state'] ?? []
        );

        $context->flowStack = $data['flow_stack'] ?? [];

        return $context;
    }

    public function persist(): void
    {
        Cache::put(
            "agent_context:{$this->sessionId}",
            $this->toArray(),
            now()->addHours(24)
        );
    }

    public static function load(string $sessionId, $userId): ?self
    {
        $data = Cache::get("agent_context:{$sessionId}");

        if (!$data) {
            return null;
        }

        return self::fromArray($data);
    }

    /**
     * Load context from cache or create new one
     */
    public static function fromCache(string $sessionId, $userId): self
    {
        $cached = self::load($sessionId, $userId);

        if ($cached) {
            \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('Context loaded from cache', [
                'session_id' => $sessionId,
                'conversation_history_count' => count($cached->conversationHistory),
                'runtime_state_keys' => array_keys($cached->runtimeState),
                'current_flow' => $cached->currentFlow,
                'current_step' => $cached->currentStep,
            ]);

            return $cached;
        }

        \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('Creating new context (not found in cache)', [
            'session_id' => $sessionId,
        ]);

        // Create new context if not found
        return new self($sessionId, $userId);
    }
}
