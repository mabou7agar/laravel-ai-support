<?php

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Unified Action Manager
 *
 * Single entry point for all action-related operations
 */
class ActionManager
{
    public function __construct(
        protected ActionRegistry $registry,
        protected ActionParameterExtractor $extractor,
        protected ActionExecutionPipeline $pipeline,
        protected ?\LaravelAIEngine\Services\Agent\AgentCollectionAdapter $adapter = null
    ) {
    }

    /**
     * Get the action registry
     */
    public function getRegistry(): ActionRegistry
    {
        return $this->registry;
    }

    /**
     * Discover all available actions
     */
    public function discoverActions(): array
    {
        // Hydrate registry if empty
        if (empty($this->registry->all())) {
            $this->hydrateRegistry();
        }

        return $this->registry->all();
    }

    /**
     * Hydrate registry with discovered action models
     */
    protected function hydrateRegistry(): void
    {
        // Try to load from cache first
        $cachedActions = Cache::get('ai_action_registry_actions');

        if ($cachedActions && is_array($cachedActions)) {
            foreach ($cachedActions as $definition) {
                $this->registry->register($definition);
            }
            Log::channel('ai-engine')->debug('Hydrated ActionRegistry from cache', [
                'count' => count($cachedActions)
            ]);
            return;
        }

        if (!$this->adapter && app()->bound(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class)) {
            $this->adapter = app(\LaravelAIEngine\Services\Agent\AgentCollectionAdapter::class);
        }

        if ($this->adapter) {
            $models = $this->adapter->discoverForAgent(true); // use cache
            $definitions = [];

            foreach ($models as $model) {
                $name = $model['name'];
                $snakeName = \Illuminate\Support\Str::snake($name);

                // Map strategy to executor
                $executor = match ($model['strategy'] ?? 'quick_action') {
                    'agent_mode' => 'workflow',
                    'guided_flow' => 'interactive',
                    default => 'model_action'
                };

                $definition = [
                    'id' => "create_{$snakeName}",
                    'type' => 'model_action',
                    'label' => "Create {$name}",
                    'description' => $model['description'] ?? "Create a new {$name}",
                    'model_class' => $model['class'],
                    'executor' => $executor,
                    'triggers' => array_merge($model['keywords'] ?? [], ["create {$name}", "add {$name}", "new {$name}"]),
                    'enabled' => true,
                    // Store full analysis for agent usage
                    'complexity' => $model['complexity'] ?? 'SIMPLE',
                    'relationships' => $model['relationships'] ?? [],
                ];
                
                // Add workflow_class if executor is workflow
                if ($executor === 'workflow' && !empty($model['workflow_class'])) {
                    $definition['workflow_class'] = $model['workflow_class'];
                }

                $definitions[] = $definition;
                $this->registry->register($definition);

                Log::channel('ai-engine')->debug('Registered discovered action', [
                    'id' => $definition['id'],
                    'label' => $definition['label'],
                    'executor' => $executor
                ]);
            }

            // Cache the definitions for 24 hours
            Cache::put('ai_action_registry_actions', $definitions, 86400);
            Log::channel('ai-engine')->info('Cached ActionRegistry definitions', [
                'count' => count($definitions)
            ]);
        }
    }

    /**
     * Get action by ID
     */
    public function getAction(string $id): ?array
    {
        return $this->registry->get($id);
    }

    /**
     * Generate actions for conversation context
     */
    public function generateActionsForContext(
        string $message,
        array $context = [],
        ?array $intentAnalysis = null
    ): array {
        $actions = [];
        $allActions = $this->registry->getEnabled();

        Log::channel('ai-engine')->debug('Generating actions for context', [
            'message_length' => strlen($message),
            'available_actions' => count($allActions),
            'intent' => $intentAnalysis['intent'] ?? null,
        ]);

        // AI-BASED ACTION SELECTION: Use AI-suggested action if available
        $suggestedActionId = $intentAnalysis['suggested_action_id'] ?? null;
        $suggestedCollection = $intentAnalysis['suggested_collection'] ?? null;

        if ($suggestedActionId && isset($allActions[$suggestedActionId])) {
            Log::channel('ai-engine')->info('AI selected specific action', [
                'suggested_action_id' => $suggestedActionId,
                'action_label' => $allActions[$suggestedActionId]['label'] ?? $suggestedActionId,
            ]);

            // Use ONLY the AI-suggested action
            $allActions = [$suggestedActionId => $allActions[$suggestedActionId]];
        } elseif ($suggestedCollection) {
            // Fallback: Use suggested collection from intent analysis if available
            Log::channel('ai-engine')->info('Document type suggested by intent analysis', [
                'suggested_collection' => $suggestedCollection,
            ]);

            // Prioritize actions matching the suggested collection
            $prioritizedActions = [];
            $otherActions = [];

            foreach ($allActions as $id => $definition) {
                $modelClass = $definition['model_class'] ?? '';

                // Match by class name (e.g., "Bill" matches "Workdo\Account\Entities\Bill")
                if (str_contains($modelClass, $suggestedCollection)) {
                    $prioritizedActions[$id] = $definition;
                } else {
                    $otherActions[$id] = $definition;
                }
            }

            // Put suggested actions first
            if (!empty($prioritizedActions)) {
                $allActions = array_merge($prioritizedActions, $otherActions);

                Log::channel('ai-engine')->info('Actions prioritized based on document type', [
                    'prioritized_count' => count($prioritizedActions),
                    'total_count' => count($allActions),
                ]);
            }
        }

        foreach ($allActions as $id => $definition) {
            // CRITICAL: If AI suggested a specific action, ONLY process that action
            if ($suggestedActionId) {
                if ($id !== $suggestedActionId) {
                    continue; // Skip all other actions
                }
                // AI selected this action, process it WITHOUT matching check
                Log::channel('ai-engine')->info('Processing AI-suggested action', [
                    'action_id' => $id,
                    'label' => $definition['label'] ?? 'unknown',
                ]);
            } elseif (!$this->matchesContext($definition, $message, $context, $intentAnalysis)) {
                continue;
            }

            // Prepare extraction context with intent and pending action info
            $extractionContext = array_merge($context, [
                'intent' => $intentAnalysis['intent'] ?? null,
                'pending_action' => $intentAnalysis['pending_action'] ?? null,
            ]);

            // Extract parameters
            try {
                $extraction = $this->extractor->extract($message, $definition, $extractionContext);

                Log::channel('ai-engine')->debug('Parameter extraction completed', [
                    'action_id' => $id,
                    'extracted_count' => count($extraction->params ?? []),
                    'missing_count' => count($extraction->missingFields ?? []),
                    'confidence' => $extraction->confidence ?? 0,
                ]);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Parameter extraction failed', [
                    'action_id' => $id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                continue; // Skip this action if extraction fails
            }

            Log::channel('ai-engine')->debug('Checking action building conditions', [
                'action_id' => $id,
                'suggestedActionId' => $suggestedActionId,
                'id_matches' => ($suggestedActionId && $id === $suggestedActionId),
            ]);

            // ALWAYS add the action if AI suggested it, even if incomplete
            if ($suggestedActionId && $id === $suggestedActionId) {
                Log::channel('ai-engine')->info('Building AI-suggested action', [
                    'action_id' => $id,
                    'is_complete' => $extraction->isComplete(),
                    'has_high_confidence' => $extraction->hasHighConfidence(),
                    'params_count' => count($extraction->params),
                ]);

                if ($extraction->isComplete() || $extraction->hasHighConfidence()) {
                    $action = $this->buildInteractiveAction($definition, $extraction);
                    $actions[] = $action;
                    Log::channel('ai-engine')->info('Added complete action', ['action_id' => $action->id]);
                } else {
                    // Add incomplete action for user to fill
                    $action = $this->buildIncompleteAction($definition, $extraction);
                    $actions[] = $action;
                    Log::channel('ai-engine')->info('Added incomplete action', ['action_id' => $action->id]);
                }
            } elseif ($extraction->isComplete() || $extraction->hasHighConfidence()) {
                $actions[] = $this->buildInteractiveAction($definition, $extraction);
            } elseif (!empty($extraction->params)) {
                // Add incomplete action for user to fill
                $actions[] = $this->buildIncompleteAction($definition, $extraction);
            }
        }

        Log::channel('ai-engine')->info('Actions generated', [
            'count' => count($actions),
            'action_ids' => array_map(fn($a) => $a->id, $actions),
            'suggested_collection' => $suggestedCollection,
        ]);

        return $actions;
    }

    /**
     * Execute an action
     */
    public function executeAction(
        InteractiveAction $action,
        $userId,
        ?string $sessionId = null
    ): ActionResult {
        return $this->pipeline->execute($action, $userId, $sessionId);
    }

    /**
     * Execute action by ID with parameters
     */
    public function executeById(
        string $actionId,
        array $params,
        $userId,
        ?string $sessionId = null
    ): ActionResult {
        $definition = $this->registry->get($actionId);

        if (!$definition) {
            return ActionResult::failure("Action not found: {$actionId}");
        }

        $action = new InteractiveAction(
            id: $actionId,
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: $definition['label'],
            description: $definition['description'] ?? '',
            data: [
                'action_id' => $actionId,
                'params' => $params,
                'executor' => $definition['executor'],
                'model_class' => $definition['model_class'] ?? null,
            ]
        );

        return $this->executeAction($action, $userId, $sessionId);
    }

    /**
     * Find actions by trigger
     */
    public function findByTrigger(string $keyword): array
    {
        return $this->registry->findByTrigger($keyword);
    }

    /**
     * Get actions by model
     */
    public function getActionsForModel(string $modelClass): array
    {
        return $this->registry->findByModel($modelClass);
    }

    /**
     * Get registry statistics
     */
    public function getStatistics(): array
    {
        return $this->registry->getStatistics();
    }

    /**
     * Clear action cache
     */
    public function clearCache(): void
    {
        $this->registry->clearCache();
    }

    /**
     * Check if action matches context using AI intent analysis
     */
    protected function matchesContext(
        array $definition,
        string $message,
        array $context,
        ?array $intentAnalysis
    ): bool {
        // Always check basic trigger match first (most reliable)
        if ($this->basicTriggerMatch($definition, $message)) {
            return true;
        }

        // If no intent analysis available, use keyword matching
        if (!$intentAnalysis) {
            return $this->keywordBasedMatch($definition, $message);
        }

        // Use AI intent analysis to determine relevance
        return $this->aiBasedMatching($definition, $message, $intentAnalysis);
    }

    /**
     * Extract keywords from model definition for matching
     */
    protected function extractModelKeywords(array $definition): array
    {
        $keywords = [];

        // Add model class name
        if (isset($definition['model_class'])) {
            $className = class_basename($definition['model_class']);
            $keywords[] = $className;
        }

        // Add triggers
        if (isset($definition['triggers']) && is_array($definition['triggers'])) {
            $keywords = array_merge($keywords, $definition['triggers']);
        }

        // Add label words
        if (isset($definition['label'])) {
            $labelWords = preg_split('/[\s\-_]+/', $definition['label']);
            $keywords = array_merge($keywords, array_filter($labelWords, fn($w) => strlen($w) > 3));
        }

        return array_unique($keywords);
    }

    /**
     * Keyword-based matching (fallback)
     */
    protected function keywordBasedMatch(array $definition, string $message): bool
    {
        $messageLower = strtolower($message);
        $keywords = $this->extractModelKeywords($definition);

        // Check for creation intent
        $creationKeywords = ['create', 'add', 'new', 'make'];
        $hasCreationIntent = false;
        foreach ($creationKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                $hasCreationIntent = true;
                break;
            }
        }

        if (!$hasCreationIntent) {
            return false;
        }

        // Check if any model keywords match
        foreach ($keywords as $keyword) {
            $keywordLower = strtolower($keyword);

            if (str_contains($messageLower, $keywordLower)) {
                return true;
            }

            // Singular/plural
            $singular = rtrim($keywordLower, 's');
            if (strlen($singular) > 3 && str_contains($messageLower, $singular)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Basic trigger matching (fallback when no AI analysis)
     */
    protected function basicTriggerMatch(array $definition, string $message): bool
    {
        $messageLower = strtolower($message);
        $triggers = $definition['triggers'] ?? [];

        foreach ($triggers as $trigger) {
            if (str_contains($messageLower, strtolower($trigger))) {
                return true;
            }
        }

        return false;
    }

    /**
     * AI-based action matching (fallback when AI hasn't selected an action)
     * Note: This is now rarely used since AI selects actions directly
     */
    protected function aiBasedMatching(array $definition, string $message, array $intentAnalysis): bool
    {
        // Get intent details
        $intent = $intentAnalysis['intent'] ?? '';

        // Only match creation intents
        if (!in_array($intent, ['new_request', 'create', 'provide_data'])) {
            return false;
        }

        // Simple fallback: match if it's a creation intent
        // The AI should have already selected the best action via suggested_action_id
        return true;
    }

    /**
     * Check if action matches intent
     */
    protected function matchesIntent(array $definition, array $intentAnalysis): bool
    {
        $intent = $intentAnalysis['intent'] ?? '';
        $confidence = $intentAnalysis['confidence'] ?? 0;

        // Require high confidence for intent matching
        if ($confidence < 0.8) {
            return false;
        }

        $actionType = $definition['type'] ?? null;

        return match ($intent) {
            'new_request', 'create' => in_array($actionType, ['model_action', 'remote_model_action']),
            'confirm' => true, // All actions can be confirmed
            'provide_data' => true, // All actions can receive data
            default => false,
        };
    }

    /**
     * Check if message indicates creation intent
     */
    protected function isCreationIntent(string $message, ?array $intentAnalysis): bool
    {
        if ($intentAnalysis && ($intentAnalysis['intent'] ?? '') === 'new_request') {
            return true;
        }

        $creationKeywords = ['create', 'add', 'new', 'make', 'register', 'insert'];
        $messageLower = strtolower($message);

        foreach ($creationKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build interactive action from extraction
     */
    protected function buildInteractiveAction(
        array $definition,
        ExtractionResult $extraction
    ): InteractiveAction {
        $description = $this->buildActionDescription($definition, $extraction->params);

        // Determine if action is ready to execute (no missing fields)
        $isReady = empty($extraction->missing);

        return new InteractiveAction(
            id: $definition['id'] . '_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: $definition['label'],
            description: $description,
            data: [
                'action_id' => $definition['id'],
                'executor' => $definition['executor'],
                'model_class' => $definition['model_class'] ?? null,
                'node_slug' => $definition['node_slug'] ?? null,
                'params' => $extraction->params,
                'ready_to_execute' => $isReady,
                'missing_fields' => $extraction->missing,
                'confidence' => $extraction->confidence,
            ]
        );
    }

    /**
     * Build incomplete action (needs more data)
     */
    protected function buildIncompleteAction(
        array $definition,
        ExtractionResult $extraction
    ): InteractiveAction {
        $description = $this->buildActionDescription($definition, $extraction->params);
        $description .= "\n\n⚠️ **Missing:** " . implode(', ', $extraction->missing);

        return new InteractiveAction(
            id: $definition['id'] . '_incomplete_' . uniqid(),
            type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
            label: $definition['label'] . ' (Incomplete)',
            description: $description,
            data: [
                'action_id' => $definition['id'],
                'executor' => $definition['executor'],
                'model_class' => $definition['model_class'] ?? null,
                'node_slug' => $definition['node_slug'] ?? null,
                'params' => $extraction->params,
                'ready_to_execute' => false,
                'missing_fields' => $extraction->missing,
                'confidence' => $extraction->confidence,
            ]
        );
    }

    /**
     * Build action description from parameters
     */
    protected function buildActionDescription(array $definition, array $params): string
    {
        $modelName = $definition['model_name'] ?? class_basename($definition['model_class'] ?? 'Item');
        $description = "**Confirm {$modelName} Creation:**\n\n";

        foreach ($params as $key => $value) {
            if ($key === '_resolve_relationships' || is_object($value)) {
                continue;
            }

            if (is_array($value)) {
                $description .= "**" . ucfirst(str_replace('_', ' ', $key)) . ":** " . count($value) . " items\n";
            } else {
                $displayValue = is_string($value) && strlen($value) > 100
                    ? substr($value, 0, 100) . '...'
                    : $value;
                $description .= "**" . ucfirst(str_replace('_', ' ', $key)) . ":** {$displayValue}\n";
            }
        }

        return $description;
    }
}
