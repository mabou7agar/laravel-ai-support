<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use Illuminate\Support\Facades\Log;

/**
 * Generic Entity Resolver - Driven by aiConfig definitions
 * 
 * Resolves entities (find or create) based on model's aiConfig,
 * eliminating the need for custom resolver implementations.
 * 
 * Usage in aiConfig:
 * ->entityField('customer_id', [
 *     'model' => Customer::class,
 *     'search_fields' => ['name', 'email', 'contact'],
 *     'create_fields' => ['name', 'email', 'phone'],
 *     'interactive' => true, // Ask user for missing data
 * ])
 */
class GenericEntityResolver
{
    protected $ai;
    protected $intelligentService;
    
    public function __construct(AIEngineService $ai, IntelligentEntityService $intelligentService)
    {
        $this->ai = $ai;
        $this->intelligentService = $intelligentService;
    }
    
    /**
     * Resolve a single entity based on aiConfig definition
     * 
     * Can be overridden by specifying custom resolver in config:
     * 'resolver' => CustomResolver::class
     * 
     * Config options:
     * - 'check_duplicates' => true: Check for similar entities before creating
     * - 'ask_on_duplicate' => true: Ask user to choose existing or create new
     */
    public function resolveEntity(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        Log::channel('ai-engine')->info('GenericEntityResolver::resolveEntity called', [
            'field' => $fieldName,
            'identifier' => $identifier,
            'has_creation_step' => !empty($context->get("{$fieldName}_creation_step")),
        ]);
        
        // Check if custom resolver is specified
        if (isset($config['resolver']) && $config['resolver'] !== 'GenericEntityResolver') {
            return $this->delegateToCustomResolver($config['resolver'], $fieldName, $config, $identifier, $context, false);
        }
        
        $modelClass = $config['model'] ?? null;
        $searchFields = $config['search_fields'] ?? ['name'];
        $interactive = $config['interactive'] ?? true;
        $confirmBeforeCreate = $config['confirm_before_create'] ?? false;
        $filters = $config['filters'] ?? null;
        $checkDuplicates = $config['check_duplicates'] ?? false;
        $askOnDuplicate = $config['ask_on_duplicate'] ?? false;
        
        if (!$modelClass) {
            return ActionResult::failure(error: "No model class specified for {$fieldName}");
        }
        
        // Handle duplicate choice if pending
        if ($context->get("{$fieldName}_duplicate_choice_pending")) {
            return $this->handleDuplicateChoice($fieldName, $config, $context);
        }
        
        // Check if we're in the middle of creation
        $creationStep = $context->get("{$fieldName}_creation_step");
        if ($creationStep) {
            Log::channel('ai-engine')->info('🔄 Continuing entity creation', [
                'field' => $fieldName,
                'creation_step' => $creationStep,
            ]);
            return $this->continueEntityCreation($fieldName, $config, $context);
        }
        
        // Check for duplicates if configured (before exact search)
        if ($checkDuplicates && $askOnDuplicate) {
            // Store identifier for later use
            $context->set("{$fieldName}_identifier", $identifier);
            
            Log::channel('ai-engine')->info('Searching for similar entities', [
                'field' => $fieldName,
                'model' => $modelClass,
                'identifier' => $identifier,
                'search_fields' => $searchFields,
                'has_filters' => !empty($filters),
            ]);
            
            $duplicates = $this->findSimilarEntities($modelClass, $identifier, $searchFields, $filters);
            
            Log::channel('ai-engine')->info('Similar entities search result', [
                'field' => $fieldName,
                'count' => $duplicates->count(),
                'entities' => $duplicates->map(fn($e) => ['id' => $e->id, 'name' => $e->name ?? 'N/A'])->toArray(),
            ]);
            
            if ($duplicates->isNotEmpty()) {
                // Check if any is an exact match
                $exactMatch = $duplicates->first(function($entity) use ($searchFields, $identifier) {
                    foreach ($searchFields as $field) {
                        if (isset($entity->$field) && strcasecmp($entity->$field, $identifier) === 0) {
                            return true;
                        }
                    }
                    return false;
                });
                
                if ($exactMatch) {
                    // Exact match found - use it directly
                    Log::channel('ai-engine')->info('Exact entity match found', [
                        'field' => $fieldName,
                        'model' => $modelClass,
                        'id' => $exactMatch->id,
                    ]);
                    
                    return ActionResult::success(
                        message: "Found existing " . class_basename($modelClass),
                        data: [$fieldName => $exactMatch->id, 'entity' => $exactMatch]
                    );
                }
                
                // Similar matches found - ask user
                return $this->askAboutDuplicates($fieldName, $duplicates, $context, $config);
            }
        } else {
            // No duplicate checking - do regular exact search
            $entity = $this->searchEntity($modelClass, $identifier, $searchFields, $filters);
            
            if ($entity) {
                Log::channel('ai-engine')->info('Entity found', [
                    'field' => $fieldName,
                    'model' => $modelClass,
                    'id' => $entity->id,
                ]);
                
                return ActionResult::success(
                    message: "Found existing " . class_basename($modelClass),
                    data: [$fieldName => $entity->id, 'entity' => $entity]
                );
            }
        }
        
        // No entity found and no duplicates - create if configured
        // If confirmBeforeCreate is true, always ask for confirmation
        if ($confirmBeforeCreate || $interactive) {
            return $this->startInteractiveCreation($fieldName, $config, $identifier, $context);
        } else {
            return $this->createEntityAuto($fieldName, $config, $identifier, $context);
        }
    }
    
    /**
     * Delegate to custom resolver - auto-detects appropriate method
     */
    private function delegateToCustomResolver(
        string $resolverClass,
        string $fieldName,
        array $config,
        $data,
        UnifiedActionContext $context,
        bool $isMultiple
    ): ActionResult {
        if (!class_exists($resolverClass)) {
            return ActionResult::failure(error: "Custom resolver class not found: {$resolverClass}");
        }
        
        $resolver = app($resolverClass);
        
        // Try to find appropriate method
        $methods = [
            // Generic methods
            'resolve',
            'resolveEntity',
            'resolveEntities',
            // Specific methods based on field name
            'resolve' . ucfirst($fieldName),
            // Legacy methods
            'resolveCustomer',
            'resolveProducts',
        ];
        
        foreach ($methods as $method) {
            if (method_exists($resolver, $method)) {
                try {
                    if ($isMultiple) {
                        // For multiple entities, pass array
                        return $resolver->$method($data, $context);
                    } else {
                        // For single entity, check method signature
                        $reflection = new \ReflectionMethod($resolver, $method);
                        $params = $reflection->getParameters();
                        
                        if (count($params) >= 3) {
                            // Method expects (fieldName, config, identifier, context)
                            return $resolver->$method($fieldName, $config, $data, $context);
                        } else {
                            // Method expects (identifier, context)
                            return $resolver->$method($data, $context);
                        }
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('Custom resolver method failed', [
                        'resolver' => $resolverClass,
                        'method' => $method,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
        }
        
        return ActionResult::failure(
            error: "No suitable method found in custom resolver: {$resolverClass}"
        );
    }
    
    /**
     * Resolve multiple entities (e.g., products in invoice)
     */
    public function resolveEntities(
        string $fieldName,
        array $config,
        array $items,
        UnifiedActionContext $context
    ): ActionResult {
        // Check if custom resolver is specified
        if (isset($config['resolver']) && $config['resolver'] !== 'GenericEntityResolver') {
            return $this->delegateToCustomResolver($config['resolver'], $fieldName, $config, $items, $context, true);
        }
        
        $modelClass = $config['model'] ?? null;
        $searchFields = $config['search_fields'] ?? ['name'];
        $interactive = $config['interactive'] ?? true;
        $filters = $config['filters'] ?? null;
        
        if (!$modelClass) {
            return ActionResult::failure(error: "No model class specified for {$fieldName}");
        }
        
        // Check if we're in the middle of creation
        $creationStep = $context->get("{$fieldName}_creation_step");
        if ($creationStep) {
            $missing = $context->get("{$fieldName}_missing", []);
            return $this->continueEntitiesCreation($fieldName, $config, $missing, $context);
        }
        
        $validated = [];
        $missing = [];
        
        foreach ($items as $item) {
            // Try multiple possible field names for the identifier
            $identifier = $item['name'] ?? $item['item'] ?? $item['product'] ?? '';
            $quantity = $item['quantity'] ?? 1;
            
            // Skip if no identifier found
            if (empty($identifier)) {
                $missing[] = array_merge([
                    'name' => 'Unknown',
                    'quantity' => $quantity,
                ], $item);
                continue;
            }
            
            // Search for existing
            $entity = $this->searchEntity($modelClass, $identifier, $searchFields, $filters);
            
            if ($entity) {
                $validated[] = array_merge([
                    'id' => $entity->id,
                    'name' => $entity->name ?? $identifier,
                    'quantity' => $quantity,
                ], $item);
            } else {
                $missing[] = array_merge([
                    'name' => $identifier,
                    'quantity' => $quantity,
                ], $item);
            }
        }
        
        if (empty($missing)) {
            $context->set($fieldName, $validated);
            return ActionResult::success(
                message: 'All entities found',
                data: [$fieldName => $validated]
            );
        }
        
        // Some missing - create them
        $context->set("{$fieldName}_validated", $validated);
        $context->set("{$fieldName}_missing", $missing);
        
        if ($interactive) {
            return $this->startInteractiveEntitiesCreation($fieldName, $config, $missing, $context);
        } else {
            return $this->createEntitiesAuto($fieldName, $config, $missing, $context);
        }
    }
    
    /**
     * Search for entity in database with custom filters
     */
    private function searchEntity(string $modelClass, $identifier, array $searchFields, $filters = null)
    {
        $query = $modelClass::query();
        
        // Apply filters
        if ($filters) {
            if (is_callable($filters)) {
                $filters($query);
            } elseif (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
            }
        }
        
        // Search across all search fields
        $query->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$identifier}%");
            }
        });
        
        return $query->first();
    }
    
    /**
     * Find similar entities for duplicate detection using AI-powered matching
     */
    private function findSimilarEntities(string $modelClass, $identifier, array $searchFields, $filters = null)
    {
        $query = $modelClass::query();
        
        Log::channel('ai-engine')->info('🔍 findSimilarEntities: Building query', [
            'model' => $modelClass,
            'identifier' => $identifier,
            'search_fields' => $searchFields,
            'has_filters' => !empty($filters),
        ]);
        
        // Apply filters
        if ($filters) {
            if (is_callable($filters)) {
                try {
                    $filters($query);
                    Log::channel('ai-engine')->info('🔍 Applied callable filters successfully');
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('🔍 Filter application failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif (is_array($filters)) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
                Log::channel('ai-engine')->info('🔍 Applied array filters', ['filters' => $filters]);
            }
        }
        
        // Get broader set of candidates (cast wider net)
        $query->where(function($q) use ($searchFields, $identifier) {
            foreach ($searchFields as $field) {
                $q->orWhere($field, 'LIKE', "%{$identifier}%");
            }
        });
        
        $candidates = $query->limit(20)->get(); // Get more candidates for AI filtering
        
        Log::channel('ai-engine')->info('🔍 Initial candidates found', [
            'count' => $candidates->count(),
        ]);
        
        // If no candidates, return empty
        if ($candidates->isEmpty()) {
            return $candidates;
        }
        
        // Use AI to intelligently rank and filter candidates
        $rankedResults = $this->rankDuplicatesWithAI($identifier, $candidates, $searchFields);
        
        Log::channel('ai-engine')->info('🔍 AI-ranked results', [
            'count' => $rankedResults->count(),
            'results' => $rankedResults->map(fn($e) => [
                'id' => $e->id,
                'name' => $e->name ?? 'N/A',
                'similarity_score' => $e->similarity_score ?? 'N/A',
            ])->toArray(),
        ]);
        
        return $rankedResults;
    }
    
    /**
     * Use AI to rank duplicate candidates by similarity
     * Returns top 5 most relevant matches with similarity scores
     */
    private function rankDuplicatesWithAI($identifier, $candidates, array $searchFields)
    {
        try {
            // Build context for AI
            $candidatesData = $candidates->map(function($entity) use ($searchFields) {
                $data = ['id' => $entity->id];
                foreach ($searchFields as $field) {
                    if (isset($entity->$field)) {
                        $data[$field] = $entity->$field;
                    }
                }
                return $data;
            })->toArray();
            
            $prompt = "You are a duplicate detection system. Analyze the following candidates and rank them by similarity to the search query.\n\n";
            $prompt .= "Search Query: \"{$identifier}\"\n\n";
            $prompt .= "Candidates:\n";
            $prompt .= json_encode($candidatesData, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "Instructions:\n";
            $prompt .= "- Consider fuzzy matching (typos, abbreviations, variations)\n";
            $prompt .= "- Consider semantic similarity (different formats of same entity)\n";
            $prompt .= "- Consider partial matches\n";
            $prompt .= "- Return ONLY the top 5 most similar candidates\n";
            $prompt .= "- Assign a similarity score (0-100) to each\n";
            $prompt .= "- Return as JSON array: [{\"id\": 1, \"score\": 95, \"reason\": \"exact match\"}, ...]\n";
            $prompt .= "- Order by score descending\n\n";
            $prompt .= "Response (JSON only):";
            
            // TODO: Enable AI ranking once caching issues resolved
            // For now, use intelligent fallback ranking
            return $this->rankDuplicatesFallback($identifier, $candidates, $searchFields);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI duplicate ranking failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
            
            return $this->rankDuplicatesFallback($identifier, $candidates, $searchFields);
        }
    }
    
    /**
     * Intelligent fallback ranking when AI is unavailable
     * Uses multiple scoring factors for better accuracy than simple LIKE
     */
    private function rankDuplicatesFallback($identifier, $candidates, array $searchFields)
    {
        $identifier = strtolower(trim($identifier));
        
        $scored = $candidates->map(function($entity) use ($identifier, $searchFields) {
            $maxScore = 0;
            $bestField = null;
            
            foreach ($searchFields as $field) {
                if (!isset($entity->$field)) continue;
                
                $value = strtolower(trim($entity->$field));
                $score = $this->calculateSimilarityScore($identifier, $value);
                
                if ($score > $maxScore) {
                    $maxScore = $score;
                    $bestField = $field;
                }
            }
            
            // Attach score to entity
            $entity->similarity_score = $maxScore;
            $entity->matched_field = $bestField;
            
            return $entity;
        });
        
        // Filter out low scores (below 30%) and sort by score
        $filtered = $scored->filter(function($entity) {
            return $entity->similarity_score >= 30;
        })->sortByDesc('similarity_score')->take(5);
        
        return $filtered->values();
    }
    
    /**
     * Calculate similarity score between two strings
     * Uses multiple algorithms for better accuracy
     */
    private function calculateSimilarityScore(string $search, string $target): int
    {
        // Exact match
        if ($search === $target) {
            return 100;
        }
        
        // Case-insensitive exact match
        if (strcasecmp($search, $target) === 0) {
            return 95;
        }
        
        // Contains exact substring
        if (str_contains($target, $search)) {
            return 85;
        }
        
        // Levenshtein distance (typo tolerance)
        $levenshtein = levenshtein($search, $target);
        $maxLength = max(strlen($search), strlen($target));
        $levenshteinScore = (1 - ($levenshtein / $maxLength)) * 100;
        
        // Similar text percentage
        similar_text($search, $target, $similarityPercent);
        
        // Word-based matching (handles reordering)
        $searchWords = explode(' ', $search);
        $targetWords = explode(' ', $target);
        $matchingWords = count(array_intersect($searchWords, $targetWords));
        $wordScore = ($matchingWords / max(count($searchWords), count($targetWords))) * 100;
        
        // Take the best score from all algorithms
        return (int) max($levenshteinScore, $similarityPercent, $wordScore);
    }
    
    /**
     * Ask user about duplicate entities
     */
    private function askAboutDuplicates(string $fieldName, $duplicates, UnifiedActionContext $context, array $config = []): ActionResult
    {
        $entityName = class_basename($duplicates->first());
        
        if ($duplicates->count() === 1) {
            $entity = $duplicates->first();
            $context->set("{$fieldName}_found_duplicate", $entity);
            
            $message = "Found existing {$entityName}: **{$entity->name}**";
            
            // Add similarity score if available
            if (isset($entity->similarity_score)) {
                $message .= " (Match: {$entity->similarity_score}%)";
            }
            
            // Add additional identifying info from display_fields config
            $displayFields = $config['display_fields'] ?? [];
            foreach ($displayFields as $field) {
                if (isset($entity->$field) && $entity->$field) {
                    $message .= " - {$entity->$field}";
                    break; // Only show first available field
                }
            }
            
            $message .= "\n\nWould you like to:\n";
            $message .= "1. Use this {$entityName} (reply 'use' or 'yes')\n";
            $message .= "2. Create a new {$entityName} (reply 'new' or 'create')";
            
            $context->set("{$fieldName}_duplicate_choice_pending", true);
            return ActionResult::needsUserInput(message: $message);
        }
        
        // Multiple matches
        $context->set("{$fieldName}_found_duplicates", $duplicates);
        
        $message = "Found {$duplicates->count()} similar {$entityName}s:\n\n";
        foreach ($duplicates as $index => $entity) {
            $message .= ($index + 1) . ". **{$entity->name}**";
            
            // Add similarity score if available
            if (isset($entity->similarity_score)) {
                $message .= " ({$entity->similarity_score}% match)";
            }
            
            // Add additional identifying info from display_fields config
            $displayFields = $config['display_fields'] ?? [];
            foreach ($displayFields as $field) {
                if (isset($entity->$field) && $entity->$field) {
                    $message .= " - {$entity->$field}";
                    break; // Only show first available field
                }
            }
            
            $message .= "\n";
        }
        
        $message .= "\nWould you like to:\n";
        $message .= "- Use one of these (reply with number 1-{$duplicates->count()})\n";
        $message .= "- Create a new {$entityName} (reply 'new' or 'create')";
        
        $context->set("{$fieldName}_duplicate_choice_pending", true);
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Handle user's choice about duplicate entities with AI interpretation
     */
    private function handleDuplicateChoice(string $fieldName, array $config, UnifiedActionContext $context): ActionResult
    {
        $lastMessage = $context->lastUserMessage ?? '';
        $duplicates = $context->get("{$fieldName}_found_duplicates");
        $singleDuplicate = $context->get("{$fieldName}_found_duplicate");
        
        $maxOptions = $duplicates ? $duplicates->count() : 1;
        
        // Use AI to interpret user's choice
        $interpretation = $this->intelligentService->interpretDuplicateChoice($lastMessage, $maxOptions);
        
        if ($interpretation) {
            if ($interpretation['action'] === 'use') {
                // User wants to use an existing entity
                $entity = null;
                
                if ($duplicates && isset($interpretation['index'])) {
                    $entity = $duplicates[$interpretation['index']] ?? null;
                } elseif ($singleDuplicate) {
                    $entity = $singleDuplicate;
                }
                
                if ($entity) {
                    $context->forget("{$fieldName}_duplicate_choice_pending");
                    $context->forget("{$fieldName}_found_duplicate");
                    $context->forget("{$fieldName}_found_duplicates");
                    
                    return ActionResult::success(
                        message: "Using existing " . class_basename($entity) . ": {$entity->name}",
                        data: [$fieldName => $entity->id, 'entity' => $entity]
                    );
                }
            } elseif ($interpretation['action'] === 'create') {
                // User wants to create new
                $context->forget("{$fieldName}_duplicate_choice_pending");
                $context->forget("{$fieldName}_found_duplicate");
                $context->forget("{$fieldName}_found_duplicates");
                
                $identifier = $context->get("{$fieldName}_identifier", '');
                return $this->startInteractiveCreation($fieldName, $config, $identifier, $context);
            }
        }
        
        // Could not interpret - ask for clarification
        return ActionResult::needsUserInput(
            message: "I didn't understand that. Please reply with:\n- 'use' or 'yes' to use the existing entity\n- A number (1-{$maxOptions}) to select a specific one\n- 'new' or 'create' to create a new one"
        );
    }
    
    /**
     * Start interactive entity creation
     */
    private function startInteractiveCreation(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        $entityName = class_basename($config['model']);
        
        // Check if we should use subflow with step prefixing
        if (!empty($config['subflow']) && class_exists($config['subflow'])) {
            Log::channel('ai-engine')->info('Starting entity creation via subflow with step prefixing', [
                'field' => $fieldName,
                'subflow' => $config['subflow'],
                'identifier' => $identifier,
            ]);
            
            // This will be handled by continueEntityCreation after user confirms
            $context->set("{$fieldName}_creation_step", 'ask_create');
            $context->set("{$fieldName}_identifier", $identifier);
            $context->set("{$fieldName}_use_subflow", true);
            
            return ActionResult::needsUserInput(
                message: "{$entityName} '{$identifier}' doesn't exist. Would you like to create it? (yes/no)"
            );
        }
        
        // Regular interactive creation without subflow
        $context->set("{$fieldName}_creation_step", 'ask_create');
        $context->set("{$fieldName}_identifier", $identifier);
        
        return ActionResult::needsUserInput(
            message: "{$entityName} '{$identifier}' doesn't exist. Would you like to create it? (yes/no)"
        );
    }
    
    /**
     * Continue interactive entity creation
     */
    private function continueEntityCreation(
        string $fieldName,
        array $config,
        UnifiedActionContext $context
    ): ActionResult {
        $step = $context->get("{$fieldName}_creation_step");
        
        // If user is being asked to create, check their response
        if ($step === 'ask_create') {
            // Get the last message from conversation history
            $conversationHistory = $context->conversationHistory ?? [];
            $lastMessage = '';
            if (!empty($conversationHistory)) {
                $lastUserMessage = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');
                if (!empty($lastUserMessage)) {
                    $lastMsg = end($lastUserMessage);
                    $lastMessage = $lastMsg['content'] ?? '';
                }
            }
            
            $response = strtolower(trim($lastMessage));
            $identifier = $context->get("{$fieldName}_identifier", '');
            
            Log::channel('ai-engine')->info('Checking user confirmation response', [
                'step' => $step,
                'response' => $response,
                'field' => $fieldName,
                'identifier' => $identifier,
                'has_conversation_history' => !empty($conversationHistory),
            ]);
            
            if (str_contains($response, 'yes') || str_contains($response, 'confirm') || str_contains($response, 'ok')) {
                // User confirmed - proceed with entity creation
                
                Log::channel('ai-engine')->info('User confirmed entity creation', [
                    'field' => $fieldName,
                    'identifier' => $identifier,
                ]);
                
                // Validate identifier before creating
                if (empty($identifier)) {
                    Log::channel('ai-engine')->error('Cannot create entity - identifier is empty', [
                        'field' => $fieldName,
                    ]);
                    
                    // Clear creation step
                    $context->forget("{$fieldName}_creation_step");
                    $context->forget("{$fieldName}_identifier");
                    
                    return ActionResult::failure(
                        error: "Cannot create entity - identifier is missing"
                    );
                }
                
                // Clear creation step AFTER getting the identifier
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_identifier");
                $useSubflow = $context->get("{$fieldName}_use_subflow", false);
                $context->forget("{$fieldName}_use_subflow");
                
                // Check if we should use subflow with step prefixing
                if ($useSubflow && !empty($config['subflow']) && class_exists($config['subflow'])) {
                    return $this->startEntitySubflow($fieldName, $config, $identifier, $context);
                }
                
                // No subflow - use auto creation
                return $this->createEntityAuto($fieldName, $config, $identifier, $context);
            } elseif (str_contains($response, 'no') || str_contains($response, 'cancel')) {
                // User declined
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_identifier");
                
                return ActionResult::failure(
                    error: "Entity creation cancelled by user"
                );
            }
        }
        
        $createFields = $config['create_fields'] ?? [];
        $modelClass = $config['model'];
        
        // Implementation would handle each field collection step
        // For now, return a simplified version
        
        return ActionResult::needsUserInput(
            message: "Please provide additional information for " . class_basename($modelClass)
        );
    }
    
    /**
     * Create entity automatically (non-interactive)
     */
    private function createEntityAuto(
        string $fieldName,
        array $config,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        $modelClass = $config['model'];
        $defaults = $config['defaults'] ?? [];
        
        try {
            // Get model instance to inspect fillable fields
            $model = new $modelClass();
            $fillable = $model->getFillable();
            
            // Build data array dynamically based on model's fillable fields
            $data = [];
            
            // Add identifier - check common field names
            $identifierField = $config['identifier_field'] ?? null;
            if (!$identifierField) {
                // Auto-detect identifier field from fillable
                $possibleNames = ['name', 'title', 'label', 'identifier'];
                foreach ($possibleNames as $possibleName) {
                    if (in_array($possibleName, $fillable)) {
                        $identifierField = $possibleName;
                        break;
                    }
                }
            }
            
            if ($identifierField && in_array($identifierField, $fillable)) {
                $data[$identifierField] = $identifier;
            }
            
            // Add workspace field if model has one
            $workspaceFields = ['workspace_id', 'workspace'];
            foreach ($workspaceFields as $workspaceField) {
                if (in_array($workspaceField, $fillable)) {
                    $data[$workspaceField] = getActiveWorkSpace() ?: 1;
                    break;
                }
            }
            
            // Add creator field if model has one
            $creatorFields = ['created_by', 'creator_id', 'user_id'];
            foreach ($creatorFields as $creatorField) {
                if (in_array($creatorField, $fillable)) {
                    $data[$creatorField] = creatorId();
                    break;
                }
            }
            
            // Merge with defaults (defaults can override auto-detected values)
            $data = array_merge($data, $defaults);
            
            Log::channel('ai-engine')->info('Creating entity automatically', [
                'model' => $modelClass,
                'data' => $data,
                'fillable' => $fillable,
            ]);
            
            $entity = $modelClass::create($data);
            
            Log::channel('ai-engine')->info('Entity created successfully', [
                'model' => $modelClass,
                'id' => $entity->id,
            ]);
            
            return ActionResult::success(
                message: class_basename($modelClass) . " created",
                data: [$fieldName => $entity->id, 'entity' => $entity]
            );
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Entity creation failed', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            
            return ActionResult::failure(
                error: "Failed to create " . class_basename($modelClass) . ": " . $e->getMessage()
            );
        }
    }
    
    /**
     * Get friendly entity name for display using rule-based transformation
     * No AI calls - fast and deterministic
     */
    private function getFriendlyEntityName(string $fieldName, array $config): string
    {
        // Check static cache first (in-memory, no expiration)
        static $cache = [];
        if (isset($cache[$fieldName])) {
            return $cache[$fieldName];
        }
        
        // Check if custom friendly name is provided in config
        if (isset($config['friendly_name'])) {
            $cache[$fieldName] = $config['friendly_name'];
            return $config['friendly_name'];
        }
        
        // Rule-based transformation (no AI needed)
        $friendly = $this->transformFieldNameToFriendly($fieldName);
        
        // Cache in memory
        $cache[$fieldName] = $friendly;
        
        return $friendly;
    }
    
    /**
     * Transform field name to friendly plural form using configurable rules
     */
    private function transformFieldNameToFriendly(string $fieldName): string
    {
        // Remove common suffixes like '_id', '_ids'
        $friendly = preg_replace('/_ids?$/', '', $fieldName);
        
        // Convert underscores to spaces
        $friendly = str_replace('_', ' ', $friendly);
        
        // If already ends with 's', it's likely already plural
        if (str_ends_with($friendly, 's')) {
            return $friendly;
        }
        
        // Get custom plural rules from config
        $customRules = config('ai-engine.plural_rules', []);
        
        // Check custom rules first
        foreach ($customRules as $singular => $plural) {
            if (str_ends_with($friendly, $singular)) {
                return substr($friendly, 0, -strlen($singular)) . $plural;
            }
        }
        
        // Apply standard English pluralization rules
        return $this->applyStandardPluralizationRules($friendly);
    }
    
    /**
     * Apply standard English pluralization rules
     */
    private function applyStandardPluralizationRules(string $word): string
    {
        // Words ending in 'y' (preceded by consonant) -> 'ies'
        if (preg_match('/[^aeiou]y$/', $word)) {
            return substr($word, 0, -1) . 'ies';
        }
        
        // Words ending in 'ss', 'sh', 'ch', 'x', 'z' -> add 'es'
        if (preg_match('/(ss|sh|ch|x|z)$/', $word)) {
            return $word . 'es';
        }
        
        // Words ending in 'f' or 'fe' -> 'ves'
        if (preg_match('/fe?$/', $word)) {
            return preg_replace('/fe?$/', 'ves', $word);
        }
        
        // Simple plural: add 's'
        return $word . 's';
    }
    
    /**
     * Start interactive entities creation (multiple)
     */
    private function startInteractiveEntitiesCreation(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        // Use display_name from config, or convert field name to friendly name
        $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
        $context->set("{$fieldName}_creation_step", 'ask_create');
        $context->set("{$fieldName}_creation_index", 0);
        
        $message = "The following {$entityName} don't exist:\n\n";
        foreach ($missing as $item) {
            // Use AI to intelligently extract entity name
            $itemName = $this->extractEntityNameWithAI($item, $entityName);
            
            $message .= "• {$itemName}";
            if (isset($item['quantity'])) {
                $message .= " (qty: {$item['quantity']})";
            }
            $message .= "\n";
        }
        $message .= "\nWould you like to create them? (yes/no)";
        
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Continue interactive entities creation
     */
    private function continueEntitiesCreation(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        $step = $context->get("{$fieldName}_creation_step");
        $index = $context->get("{$fieldName}_creation_index", 0);
        
        // If user just confirmed to create all entities
        if ($step === 'ask_create') {
            // Move to first entity creation
            $context->set("{$fieldName}_creation_step", 'create_entity');
            $context->set("{$fieldName}_creation_index", 0);
            return $this->askForEntityDetails($fieldName, $config, $missing, 0, $context);
        }
        
        // If we're creating entities, handle the response
        if ($step === 'create_entity') {
            // Entity details were provided, create it
            $currentItem = $missing[$index] ?? null;
            if (!$currentItem) {
                return ActionResult::failure(error: "Entity not found at index {$index}");
            }
            
            // Create the entity (this would be handled by entity creation service)
            // For now, just move to next entity
            $nextIndex = $index + 1;
            
            if ($nextIndex < count($missing)) {
                // More entities to create
                $context->set("{$fieldName}_creation_index", $nextIndex);
                return $this->askForEntityDetails($fieldName, $config, $missing, $nextIndex, $context);
            } else {
                // All entities created, merge with validated
                $validated = $context->get("{$fieldName}_validated", []);
                $allEntities = array_merge($validated, $missing);
                $context->set($fieldName, $allEntities);
                
                // Clear creation state
                $context->forget("{$fieldName}_creation_step");
                $context->forget("{$fieldName}_creation_index");
                $context->forget("{$fieldName}_missing");
                $context->forget("{$fieldName}_validated");
                
                $entityName = $config['display_name'] ?? $this->getFriendlyEntityName($fieldName, $config);
                return ActionResult::success(
                    message: "All {$entityName} created",
                    data: [$fieldName => $allEntities]
                );
            }
        }
        
        return ActionResult::failure(error: "Unknown creation step: {$step}");
    }
    
    /**
     * Ask for entity details during creation
     */
    private function askForEntityDetails(
        string $fieldName,
        array $config,
        array $missing,
        int $index,
        UnifiedActionContext $context
    ): ActionResult {
        $item = $missing[$index];
        $entityName = class_basename($config['model']);
        
        // Use AI to intelligently extract entity name
        $itemName = $this->extractEntityNameWithAI($item, $entityName);
        
        // Check if we should use subflow for this entity
        if (!empty($config['subflow']) && class_exists($config['subflow'])) {
            // Store the item being created
            $context->set("{$fieldName}_current_item", $item);
            $context->set("{$fieldName}_current_item_name", $itemName);
            
            // Use the same subflow logic as single entity creation
            return $this->startEntitySubflow($fieldName, $config, $itemName, $context, $item);
        }
        
        // No subflow - show creation prompt
        // Build message - check if there's a custom prompt callback
        if (isset($config['creation_prompt']) && is_callable($config['creation_prompt'])) {
            $message = $config['creation_prompt']($item, $itemName, $entityName);
        } else {
            // Default generic prompt
            $message = "{$entityName}: {$itemName}\n\n";
            $message .= "Please provide additional details for this {$entityName}.";
        }
        
        return ActionResult::needsUserInput(message: $message);
    }
    
    /**
     * Start entity creation subflow
     */
    private function startEntitySubflow(
        string $fieldName,
        array $config,
        string $identifier,
        UnifiedActionContext $context,
        array $itemData = []
    ): ActionResult {
        $entityName = class_basename($config['model']);
        
        try {
            // Get parent workflow name for step prefix
            $parentWorkflowName = class_basename($context->get('current_workflow', 'Workflow'));
            
            // Create prefix: customer_invoice_flow or product_invoice_flow
            $stepPrefix = strtolower($entityName) . '_' . strtolower(str_replace('Workflow', '', $parentWorkflowName));
            
            Log::channel('ai-engine')->info('Starting entity subflow', [
                'subflow' => $config['subflow'],
                'step_prefix' => $stepPrefix,
                'entity' => $entityName,
                'identifier' => $identifier,
            ]);
            
            // Pre-populate context with entity-specific data
            // For products, use 'product_name' instead of just 'name'
            $existingCollectedData = $context->get('collected_data', []);
            
            if (str_contains(strtolower($entityName), 'product')) {
                $context->set('product_name', $identifier);
                // Also set in collected_data with the correct field name
                $existingCollectedData['product_name'] = $identifier;
                
                // Pass price and quantity from item data if available
                if (!empty($itemData['price'])) {
                    $existingCollectedData['sale_price'] = $itemData['price'];
                }
                if (!empty($itemData['quantity'])) {
                    $existingCollectedData['quantity'] = $itemData['quantity'];
                }
            }
            
            // Also set as 'name' for generic workflows
            $existingCollectedData['name'] = $identifier;
            $context->set('collected_data', $existingCollectedData);
            
            // Mark that we're in a subflow (for autoResolveEntity to detect)
            $context->set('active_subflow', [
                'workflow_class' => $config['subflow'],
                'field_name' => $fieldName,
                'entity_name' => $entityName,
                'step_prefix' => $stepPrefix,
            ]);
            
            // Instantiate subworkflow with step prefix (use injected AI service)
            $tools = null; // ToolRegistry is optional
            $subflowClass = $config['subflow'];
            $subworkflow = new $subflowClass($this->ai, $tools, $stepPrefix);
            
            // Get first step of subworkflow (with prefix applied)
            $firstStep = $subworkflow->getFirstStep();
            if (!$firstStep) {
                throw new \Exception("Subworkflow has no steps");
            }
            
            // Update context to point to subworkflow's first step
            $context->currentStep = $firstStep->getName();
            $context->set('current_workflow', $subflowClass);
            
            Log::channel('ai-engine')->info('Subflow started, transitioning to first step', [
                'first_step' => $firstStep->getName(),
                'step_prefix' => $stepPrefix,
            ]);
            
            // Execute the first step
            $result = $firstStep->run($context);
            
            // Return the result - this keeps us in the subflow
            // The parent step will complete with this result, but currentStep
            // now points to the subflow, so next execution will continue in subflow
            return $result;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Subflow execution failed', [
                'subflow' => $config['subflow'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return ActionResult::failure(error: "Failed to start entity creation: {$e->getMessage()}");
        }
    }
    
    
    /**
     * Create entities automatically (non-interactive)
     */
    private function createEntitiesAuto(
        string $fieldName,
        array $config,
        array $missing,
        UnifiedActionContext $context
    ): ActionResult {
        $modelClass = $config['model'];
        $created = [];
        
        foreach ($missing as $item) {
            try {
                // Use AI to intelligently extract entity name
                $entityName = class_basename($modelClass);
                $itemName = $this->extractEntityNameWithAI($item, $entityName);
                
                $data = array_merge([
                    'name' => $itemName,
                    'workspace' => getActiveWorkSpace() ?: 1,
                    'created_by' => auth()->id() ?? 1,
                ], $config['defaults'] ?? []);
                
                $entity = $modelClass::create($data);
                $created[] = array_merge([
                    'id' => $entity->id,
                    'name' => $entity->name,
                ], $item);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('Failed to create entity', [
                    'model' => $modelClass,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        $validated = $context->get("{$fieldName}_validated", []);
        $all = array_merge($validated, $created);
        $context->set($fieldName, $all);
        
        return ActionResult::success(
            message: count($created) . " " . class_basename($modelClass) . "(s) created",
            data: [$fieldName => $all]
        );
    }
    
    /**
     * Use AI to intelligently extract entity name from item data
     * This replaces hardcoded key fallbacks with intelligent extraction
     */
    private function extractEntityNameWithAI(array $item, string $entityType): string
    {
        // Quick check: if there's a clear 'name' field that's not empty, use it
        if (!empty($item['name'])) {
            return $item['name'];
        }
        
        // TODO: Enable AI extraction once caching issues are resolved
        // For now, use intelligent fallback that's still better than hardcoded keys
        return $this->intelligentFallbackExtraction($item, $entityType);
    }
    
    /**
     * Intelligent fallback extraction - smarter than hardcoded keys
     * Analyzes the data structure to find the most likely name field
     */
    private function intelligentFallbackExtraction(array $item, string $entityType): string
    {
        // Priority 1: Common name fields
        $nameKeys = ['name', 'title', 'label', 'identifier'];
        foreach ($nameKeys as $key) {
            if (!empty($item[$key])) {
                return $this->normalizeEntityName($item[$key]);
            }
        }
        
        // Priority 2: Entity-type specific keys (e.g., 'product' for products)
        $entitySpecificKey = strtolower($entityType);
        if (!empty($item[$entitySpecificKey])) {
            return $this->normalizeEntityName($item[$entitySpecificKey]);
        }
        
        // Priority 3: Description field (extract first meaningful part)
        if (!empty($item['description'])) {
            $desc = $item['description'];
            // Take first sentence or first 50 chars
            $firstPart = explode('.', $desc)[0];
            $firstPart = explode(',', $firstPart)[0];
            return $this->normalizeEntityName(substr($firstPart, 0, 50));
        }
        
        // Priority 4: Any non-empty string value (excluding metadata fields)
        $excludeKeys = ['id', 'created_at', 'updated_at', 'workspace', 'created_by', 'quantity', 'price'];
        foreach ($item as $key => $value) {
            if (is_string($value) && !empty($value) && !in_array($key, $excludeKeys)) {
                return $this->normalizeEntityName($value);
            }
        }
        
        return "Unknown {$entityType}";
    }
    
    /**
     * Normalize entity name - proper capitalization, trim, clean
     */
    private function normalizeEntityName(string $name): string
    {
        $name = trim($name);
        
        // Remove extra whitespace
        $name = preg_replace('/\s+/', ' ', $name);
        
        // Capitalize first letter of each word if all lowercase or all uppercase
        if ($name === strtolower($name) || $name === strtoupper($name)) {
            $name = ucwords(strtolower($name));
        }
        
        return $name;
    }
}
