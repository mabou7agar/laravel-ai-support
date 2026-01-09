<?php

namespace LaravelAIEngine\DTOs;

use Illuminate\Support\Facades\Cache;

class UnifiedActionContext
{
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
        $this->conversationHistory[] = [
            'role' => 'user',
            'content' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        
        if (count($this->conversationHistory) > 10) {
            $this->conversationHistory = array_slice($this->conversationHistory, -10);
        }
    }

    public function addAssistantMessage(string $message): void
    {
        $this->conversationHistory[] = [
            'role' => 'assistant',
            'content' => $message,
            'timestamp' => now()->toIso8601String(),
        ];
        
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
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
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
