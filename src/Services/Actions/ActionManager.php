<?php

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Log;

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
        protected ActionExecutionPipeline $pipeline
    ) {}
    
    /**
     * Discover all available actions
     */
    public function discoverActions(): array
    {
        return $this->registry->all();
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
        
        foreach ($allActions as $id => $definition) {
            // Check if action matches context
            if (!$this->matchesContext($definition, $message, $context, $intentAnalysis)) {
                continue;
            }
            
            // Extract parameters
            $extraction = $this->extractor->extract($message, $definition, $context);
            
            // Only add action if we have reasonable confidence or all required params
            if ($extraction->isComplete() || $extraction->hasHighConfidence()) {
                $actions[] = $this->buildInteractiveAction($definition, $extraction);
            } elseif (!empty($extraction->params)) {
                // Add incomplete action for user to fill
                $actions[] = $this->buildIncompleteAction($definition, $extraction);
            }
        }
        
        Log::channel('ai-engine')->info('Actions generated', [
            'count' => count($actions),
            'action_ids' => array_map(fn($a) => $a->id, $actions),
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
     * AI-based action matching using intent analysis
     */
    protected function aiBasedMatching(array $definition, string $message, array $intentAnalysis): bool
    {
        // Extract action context
        $actionLabel = $definition['label'] ?? '';
        $actionDescription = $definition['description'] ?? '';
        $modelKeywords = $this->extractModelKeywords($definition);
        
        // Get intent details
        $intent = $intentAnalysis['intent'] ?? '';
        $extractedData = $intentAnalysis['extracted_data'] ?? [];
        $confidence = $intentAnalysis['confidence'] ?? 0;
        
        // Only match creation intents
        if (!in_array($intent, ['new_request', 'create', 'provide_data'])) {
            return false;
        }
        
        // Use AI to determine semantic relevance
        $relevanceScore = $this->calculateSemanticRelevance(
            $message,
            $actionLabel,
            $actionDescription,
            $modelKeywords,
            $extractedData
        );
        
        Log::channel('ai-engine')->debug('Action matching score', [
            'action' => $actionLabel,
            'message' => substr($message, 0, 50),
            'keywords' => $modelKeywords,
            'score' => $relevanceScore,
            'threshold' => 0.3,
            'matched' => $relevanceScore >= 0.3,
        ]);
        
        // Match if relevance score is high enough (lowered threshold for better recall)
        return $relevanceScore >= 0.3;
    }
    
    /**
     * Calculate semantic relevance between message and action
     */
    protected function calculateSemanticRelevance(
        string $message,
        string $actionLabel,
        string $actionDescription,
        array $keywords,
        array $extractedData
    ): float {
        $score = 0.0;
        $messageLower = strtolower($message);
        
        // If no keywords, this action is too generic - return low score
        if (empty($keywords)) {
            return 0.1;
        }
        
        // Check if any keywords match (with fuzzy matching)
        $matchedKeywords = 0;
        $hasStrongMatch = false;
        
        foreach ($keywords as $keyword) {
            $keywordLower = strtolower($keyword);
            
            // Skip very generic keywords
            if (in_array($keywordLower, ['item', 'data', 'record', 'entry'])) {
                continue;
            }
            
            // Exact match (strong signal)
            if (str_contains($messageLower, $keywordLower)) {
                $matchedKeywords++;
                $hasStrongMatch = true;
                continue;
            }
            
            // Singular/plural variations
            $singular = rtrim($keywordLower, 's');
            if (strlen($singular) > 3 && str_contains($messageLower, $singular)) {
                $matchedKeywords++;
                $hasStrongMatch = true;
                continue;
            }
            
            // Plural form
            if (str_contains($messageLower, $keywordLower . 's')) {
                $matchedKeywords++;
                $hasStrongMatch = true;
            }
        }
        
        // No keyword matches = not relevant
        if ($matchedKeywords == 0) {
            return 0.0;
        }
        
        // Strong keyword match is required
        if (!$hasStrongMatch) {
            return 0.2;
        }
        
        // Calculate base score from keyword matches
        $keywordScore = min(($matchedKeywords / count($keywords)), 1.0);
        $score = $keywordScore * 0.8; // Keyword match is 80% of score
        
        // Bonus for data extraction (indicates AI understood the intent)
        if (!empty($extractedData)) {
            $score += 0.2;
        }
        
        return min($score, 1.0);
    }
    
    /**
     * Extract keywords from model description and config
     */
    protected function extractModelKeywords(array $definition): array
    {
        $keywords = [];
        
        // Add model name
        $modelClass = $definition['model_class'] ?? '';
        if ($modelClass) {
            $modelName = class_basename($modelClass);
            $keywords[] = $modelName;
        }
        
        // Use description from action definition (works for both local and remote actions)
        $description = $definition['description'] ?? '';
        
        // If no description in definition, try to get from model's initializeAI
        if (!$description && $modelClass && class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    if ($method->isStatic()) {
                        $config = $modelClass::initializeAI();
                        $description = $config['description'] ?? '';
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
        
        // Extract keywords from description (only first sentence/line)
        // e.g., "Products and services catalog" -> ['products', 'services', 'catalog']
        if ($description) {
            // Take only the first sentence (before first period or newline)
            $firstSentence = preg_split('/[\.\n]/', $description)[0];
            $words = preg_split('/[\s,]+/', strtolower($firstSentence));
            $meaningfulWords = array_filter($words, function($word) {
                // Filter out common words and short words
                $stopWords = ['and', 'or', 'the', 'a', 'an', 'for', 'with', 'use', 'this', 'that', 'from', 'into', 'when', 'what', 'where', 'which', 'who'];
                return strlen($word) > 3 && !in_array($word, $stopWords);
            });
            $keywords = array_merge($keywords, $meaningfulWords);
        }
        
        return array_unique($keywords);
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
        
        return match($intent) {
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
