<?php

namespace LaravelAIEngine\DTOs;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Enums\EntityState;

class UnifiedActionContext
{
    public array $workflowStack = [];
    
    public function __construct(
        public string $sessionId,
        public $userId,
        public array $conversationHistory = [],
        public ?array $pendingAction = null,
        public ?array $dataCollectorState = null,
        public array $extractedData = [],
        public array $validationErrors = [],
        public string $currentStrategy = 'conversational',
        public ?array $intentAnalysis = null,
        public array $metadata = [],
        public ?string $currentWorkflow = null,
        public ?string $currentStep = null,
        public array $workflowState = []
    ) {}

    public function addUserMessage(string $message): void
    {
        // Limit message length to prevent memory bloat
        $truncatedMessage = strlen($message) > 1000 ? substr($message, 0, 1000) . '...' : $message;
        
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $truncatedMessage,
            'timestamp' => now()->toIso8601String(),
        ];
        
        // Keep only last 10 messages to prevent memory issues
        if (count($this->conversationHistory) > 10) {
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
        }
    }

    public function addAssistantMessage(string $message): void
    {
        // Limit message length to prevent memory bloat
        $truncatedMessage = strlen($message) > 1000 ? substr($message, 0, 1000) . '...' : $message;
        
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $truncatedMessage,
            'timestamp' => now()->toIso8601String(),
        ];
        
        // Keep only last 10 messages to prevent memory issues
        if (count($this->conversationHistory) > 10) {
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
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
        $this->workflowState[$key] = $value;
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
        $this->workflowState[$key] = $value;
        
        // Track entity state metadata
        $this->workflowState["_entity_states"][$entity] = [
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
        return $this->workflowState[$key] ?? $default;
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
        return isset($this->workflowState[$key]);
    }
    
    /**
     * Get current state of an entity
     * 
     * @param string $entity Entity name
     * @return EntityState|null Current state or null if not tracked
     */
    public function getCurrentEntityState(string $entity): ?EntityState
    {
        $stateValue = $this->workflowState["_entity_states"][$entity]['state'] ?? null;
        
        if (!$stateValue) {
            return null;
        }
        
        return EntityState::from($stateValue);
    }

    public function get(string $key, $default = null)
    {
        return $this->workflowState[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->workflowState[$key]);
    }

    public function forget(string $key): void
    {
        unset($this->workflowState[$key]);
    }

    /**
     * Push current workflow onto stack and start new workflow
     */
    public function pushWorkflow(string $workflowClass, ?string $step = null, array $state = []): void
    {
        $this->workflowStack[] = [
            'workflow' => $this->currentWorkflow,
            'step' => $this->currentStep,
            'state' => $this->workflowState,
        ];
        
        $this->currentWorkflow = $workflowClass;
        $this->currentStep = $step;
        // Merge the new state with existing state (don't replace)
        $this->workflowState = array_merge($this->workflowState, $state);
    }
    
    /**
     * Pop workflow from stack and restore parent workflow
     */
    public function popWorkflow(): ?array
    {
        if (empty($this->workflowStack)) {
            return null;
        }
        
        $parent = array_pop($this->workflowStack);
        
        $this->currentWorkflow = $parent['workflow'];
        $this->currentStep = $parent['step'];
        $this->workflowState = $parent['state'];
        
        return $parent;
    }
    
    /**
     * Check if we're in a subworkflow
     */
    public function isInSubworkflow(): bool
    {
        return !empty($this->workflowStack);
    }
    
    /**
     * Get parent workflow info without popping
     */
    public function getParentWorkflow(): ?array
    {
        if (empty($this->workflowStack)) {
            return null;
        }
        
        return end($this->workflowStack);
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
            'current_workflow' => $this->currentWorkflow,
            'current_step' => $this->currentStep,
            'workflow_state' => $this->workflowState,
            'workflow_stack' => $this->workflowStack,
        ];
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
            currentWorkflow: $data['current_workflow'] ?? null,
            currentStep: $data['current_step'] ?? null,
            workflowState: $data['workflow_state'] ?? []
        );
        
        $context->workflowStack = $data['workflow_stack'] ?? [];
        
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
                'workflow_state_keys' => array_keys($cached->workflowState),
                'current_workflow' => $cached->currentWorkflow,
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
