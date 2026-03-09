<?php

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use Illuminate\Support\Facades\Log;

/**
 * Action Parameter Extractor
 * 
 * Extracts parameters from conversation context using AI
 */
class ActionParameterExtractor
{
    protected ?AIEngineService $aiService = null;
    protected ?LocaleResourceService $localeResources = null;

    public function __construct(?LocaleResourceService $localeResources = null)
    {
        $this->localeResources = $localeResources;
    }
    
    /**
     * Lazy load AIEngineService to prevent circular dependency
     */
    protected function getAIService(): AIEngineService
    {
        if ($this->aiService === null) {
            $this->aiService = app(AIEngineService::class);
        }
        return $this->aiService;
    }
    
    /**
     * Extract parameters for an action
     */
    public function extract(
        string $message,
        array $actionDefinition,
        array $context = []
    ): ExtractionResult {
        $startTime = microtime(true);
        
        $modelClass = $actionDefinition['model_class'] ?? null;
        $parameters = $actionDefinition['parameters'] ?? [];
        
        // CRITICAL: Smart context isolation to prevent hallucination
        // - For NEW actions: Use ONLY current message (no history contamination)
        // - For MODIFY intent: Use conversation from action start point only
        $conversationContext = $this->buildActionScopedContext($message, $context);
        
        // Check if model supports function calling
        $useFunctionCalling = $modelClass && 
                             class_exists($modelClass) && 
                             method_exists($modelClass, 'getFunctionSchema');
        
        $extracted = null;
        
        if ($useFunctionCalling) {
            $extracted = $this->extractWithFunctionCalling($modelClass, $message, $conversationContext);
        }
        
        if ($extracted === null) {
            $extracted = $this->extractWithPrompt($message, $actionDefinition, $conversationContext);
        }

        // Deterministic fallback for common fields when AI extraction is unavailable.
        $heuristic = $this->extractHeuristically($message, $actionDefinition, $context);
        $extracted = array_merge($heuristic, $extracted ?? []);
        
        // Smart merge: Move standalone fields into array structures where appropriate
        $extracted = $this->smartMergeParameters($extracted, $actionDefinition);
        
        // Validate and find missing fields
        $missing = $this->findMissingFields($extracted, $actionDefinition);
        $confidence = $this->calculateConfidence($extracted, $actionDefinition);
        
        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        
        Log::channel('ai-engine')->debug('Parameter extraction completed', [
            'action_id' => $actionDefinition['id'] ?? 'unknown',
            'extracted_count' => count($extracted),
            'missing_count' => count($missing),
            'confidence' => $confidence,
            'duration_ms' => $durationMs,
        ]);
        
        return new ExtractionResult(
            params: $extracted,
            missing: $missing,
            confidence: $confidence,
            durationMs: $durationMs
        );
    }
    
    /**
     * Extract using function calling (strict type safety)
     */
    protected function extractWithFunctionCalling(
        string $modelClass,
        string $message,
        string $context
    ): ?array {
        if (!$this->getAIService()) {
            return null;
        }
        
        try {
            $functionSchema = $modelClass::getFunctionSchema();
            
            $aiRequest = (new AIRequest(
                prompt: $this->runtimeText(
                    'ai-engine::runtime.action_parameter_extractor.function_call_prompt',
                    '',
                    ['message' => $message, 'context' => $context]
                ),
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                systemPrompt: $this->runtimeText(
                    'ai-engine::runtime.action_parameter_extractor.function_call_system_prompt',
                    ''
                ),
                maxTokens: 500
            ))->withFunctions([$functionSchema], ['name' => $functionSchema['name']]);
            
            $response = $this->getAIService()->generate($aiRequest);
            
            if (isset($response->functionCall) && isset($response->functionCall['arguments'])) {
                return json_decode($response->functionCall['arguments'], true);
            }
            
            // Fallback to content parsing
            $aiContent = $response->getContent();
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiContent, $matches)) {
                $aiContent = $matches[1];
            }
            return json_decode($aiContent, true);
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Function calling extraction failed', [
                'model' => $modelClass,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    
    /**
     * Extract using prompt-based approach
     */
    protected function extractWithPrompt(
        string $message,
        array $actionDefinition,
        string $context
    ): array {
        $prompt = $this->buildExtractionPrompt($message, $actionDefinition, $context);
        
        try {
            $aiService = $this->getAIService();

            $aiRequest = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                systemPrompt: $this->runtimeText(
                    'ai-engine::runtime.action_parameter_extractor.prompt_system_prompt',
                    ''
                ),
                maxTokens: 800
            );
            
            $response = $aiService->generate($aiRequest);
            $content = $response->getContent();
            
            // Extract JSON from response
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            $extracted = json_decode($content, true);
            
            if (!is_array($extracted)) {
                Log::channel('ai-engine')->warning('Failed to parse extraction result', [
                    'content' => $content,
                ]);
                return [];
            }
            
            Log::channel('ai-engine')->debug('Raw AI extraction result', [
                'action' => $actionDefinition['label'] ?? 'unknown',
                'extracted' => $extracted,
                'has_items' => isset($extracted['items']),
                'items_count' => isset($extracted['items']) ? count($extracted['items']) : 0,
            ]);
            
            return $extracted;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Prompt-based extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Build extraction prompt
     */
    protected function buildExtractionPrompt(
        string $message,
        array $actionDefinition,
        string $context
    ): string {
        $modelName = $actionDefinition['label'] ?? 'item';
        $parameters = $actionDefinition['parameters'] ?? [];
        $fields = $parameters['fields'] ?? [];

        $fieldsBlock = $this->buildFieldsBlock($fields);
        $fallbackFieldsLine = '';
        if ($fieldsBlock === '') {
            $allFields = array_merge(
                $actionDefinition['required_params'] ?? [],
                $actionDefinition['optional_params'] ?? []
            );
            if ($allFields !== []) {
                $fallbackFieldsLine = $this->runtimeText(
                    'ai-engine::runtime.action_parameter_extractor.fields_to_extract_line',
                    '',
                    ['fields' => implode(', ', $allFields)]
                );
            }
        }

        $extractionHints = $parameters['extraction_hints'] ?? [];
        $extractionHintsBlock = $this->buildExtractionHintsBlock($extractionHints);

        return $this->renderPromptTemplate(
            'action_parameter_extractor/extraction_prompt',
            [
                'model_name' => (string) $modelName,
                'message' => $message,
                'conversation_context' => $context,
                'fields_block' => $fieldsBlock,
                'fallback_fields_line' => $fallbackFieldsLine,
                'extraction_hints_block' => $extractionHintsBlock,
            ],
            $this->runtimeText(
                'ai-engine::runtime.action_parameter_extractor.prompt_fallback',
                '',
                ['model_name' => (string) $modelName, 'message' => $message]
            )
        );
    }

    protected function buildFieldsBlock(array $fields): string
    {
        if ($fields === []) {
            return '';
        }

        $requiredLabel = $this->runtimeText('ai-engine::runtime.action_parameter_extractor.required_label');
        $optionalLabel = $this->runtimeText('ai-engine::runtime.action_parameter_extractor.optional_label');
        $itemStructureHeader = $this->runtimeText('ai-engine::runtime.action_parameter_extractor.item_structure_header');
        $exampleHeader = $this->runtimeText('ai-engine::runtime.action_parameter_extractor.example_header');

        $lines = [];
        foreach ($fields as $fieldName => $fieldInfo) {
            $description = is_array($fieldInfo) ? ($fieldInfo['description'] ?? $fieldName) : $fieldInfo;
            $type = is_array($fieldInfo) ? ($fieldInfo['type'] ?? 'string') : 'string';
            $required = is_array($fieldInfo) ? ($fieldInfo['required'] ?? false) : false;
            $requiredSuffix = $required ? " {$requiredLabel}" : " {$optionalLabel}";

            $lines[] = "- {$fieldName} ({$type}){$requiredSuffix}: {$description}";

            if ($type === 'array' && isset($fieldInfo['item_structure']) && is_array($fieldInfo['item_structure'])) {
                $lines[] = "  {$itemStructureHeader} {$fieldName}:";
                foreach ($fieldInfo['item_structure'] as $itemField => $itemFieldInfo) {
                    $itemDesc = is_array($itemFieldInfo) ? ($itemFieldInfo['description'] ?? $itemField) : $itemFieldInfo;
                    $itemType = is_array($itemFieldInfo) ? ($itemFieldInfo['type'] ?? 'string') : 'string';
                    $itemRequired = is_array($itemFieldInfo) ? ($itemFieldInfo['required'] ?? false) : false;
                    $itemReqSuffix = $itemRequired ? " {$requiredLabel}" : " {$optionalLabel}";
                    $lines[] = "    * {$itemField} ({$itemType}){$itemReqSuffix}: {$itemDesc}";
                }

                if (isset($fieldInfo['examples']) && is_array($fieldInfo['examples']) && !empty($fieldInfo['examples'])) {
                    $lines[] = "  {$exampleHeader} {$fieldName}:";
                    $lines[] = '    ' . json_encode($fieldInfo['examples'][0], JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return implode("\n", $lines);
    }

    protected function buildExtractionHintsBlock(array $extractionHints): string
    {
        if ($extractionHints === []) {
            return '';
        }

        $lines = [];
        foreach ($extractionHints as $field => $hints) {
            $lines[] = "- {$field}: {$hints}";
        }

        return implode("\n", $lines);
    }

    protected function renderPromptTemplate(string $template, array $replace, string $fallback): string
    {
        $rendered = $this->locale()->renderPromptTemplate($template, $replace, $this->localeCode());
        return $rendered !== '' ? $rendered : $fallback;
    }

    protected function runtimeText(string $key, string $fallback = '', array $replace = []): string
    {
        $locale = $this->localeCode();
        $translated = $this->locale()->translation($key, $replace, $locale);
        if ($translated !== '') {
            return $translated;
        }

        $fallbackLocale = $this->locale()->resolveLocale(
            (string) (config('ai-engine.localization.fallback_locale') ?: config('app.fallback_locale') ?: app()->getLocale())
        );
        if ($fallbackLocale !== $locale) {
            $translated = $this->locale()->translation($key, $replace, $fallbackLocale);
            if ($translated !== '') {
                return $translated;
            }
        }

        if ($fallback === '') {
            return '';
        }

        $fallbackReplace = [];
        foreach ($replace as $name => $value) {
            $fallbackReplace[':' . $name] = (string) $value;
        }

        return strtr($fallback, $fallbackReplace);
    }

    protected function localeCode(): string
    {
        return $this->locale()->resolveLocale(app()->getLocale());
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }
    
    /**
     * Build action-scoped conversation context
     * 
     * For NEW actions: Returns empty (no history contamination)
     * For MODIFY intent: Returns conversation from action start point only
     */
    protected function buildActionScopedContext(string $message, array $context): string
    {
        // Check if this is a modify intent with pending action
        $pendingAction = $context['pending_action'] ?? null;
        $intent = $context['intent'] ?? null;
        
        Log::channel('ai-engine')->debug('Building action-scoped context', [
            'has_pending_action' => $pendingAction !== null,
            'intent' => $intent,
            'will_include_history' => $pendingAction && $intent === 'modify',
        ]);
        
        // For NEW actions or no pending action: NO history (prevent contamination)
        if (!$pendingAction || $intent !== 'modify') {
            return '';
        }
        
        // For MODIFY intent: Include conversation from action start point
        $conversationHistory = $context['conversation_history'] ?? [];
        $actionStartIndex = $context['action_start_index'] ?? null;
        
        if (empty($conversationHistory) || $actionStartIndex === null) {
            return '';
        }
        
        // Get messages from action start to current (action-scoped context)
        $actionScopedMessages = array_slice($conversationHistory, $actionStartIndex);
        
        $contextStr = "Action Context (from action start):\n";
        foreach ($actionScopedMessages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $contextStr .= "{$role}: {$content}\n";
        }
        
        Log::channel('ai-engine')->debug('Action-scoped context built', [
            'message_count' => count($actionScopedMessages),
            'context_length' => strlen($contextStr),
        ]);
        
        return $contextStr;
    }
    
    /**
     * Build conversation context string (legacy - kept for compatibility)
     */
    protected function buildConversationContext(string $message, array $context): string
    {
        $conversationHistory = $context['conversation_history'] ?? [];
        
        if (empty($conversationHistory)) {
            return '';
        }
        
        $contextStr = '';
        $recentMessages = array_slice($conversationHistory, -5);
        
        foreach ($recentMessages as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $contextStr .= "{$role}: {$content}\n";
        }
        
        return $contextStr;
    }

    protected function extractHeuristically(string $message, array $actionDefinition, array $context): array
    {
        $fields = array_values(array_unique(array_merge(
            $actionDefinition['required_params'] ?? [],
            $actionDefinition['optional_params'] ?? []
        )));

        if (empty($fields)) {
            return [];
        }

        $data = [];
        $history = $context['conversation_history'] ?? [];

        foreach ($fields as $field) {
            $fieldKey = strtolower((string) $field);

            if (!isset($data[$field]) && str_contains($fieldKey, 'price')) {
                $price = $this->extractPriceValue($message);
                if ($price === null) {
                    foreach (array_reverse($history) as $msg) {
                        $price = $this->extractPriceValue((string) ($msg['content'] ?? ''));
                        if ($price !== null) {
                            break;
                        }
                    }
                }
                if ($price !== null) {
                    $data[$field] = $price;
                }
            }

            if (!isset($data[$field]) && str_contains($fieldKey, 'name')) {
                $name = $this->extractNameValue($message);
                if ($name === null) {
                    foreach (array_reverse($history) as $msg) {
                        if (($msg['role'] ?? 'user') !== 'user') {
                            continue;
                        }
                        $name = $this->extractNameValue((string) ($msg['content'] ?? ''));
                        if ($name !== null) {
                            break;
                        }
                    }
                }
                if ($name !== null) {
                    $data[$field] = $name;
                }
            }
        }

        return $data;
    }

    protected function extractPriceValue(string $text): ?float
    {
        if (preg_match('/(?:\\$|usd\\s*)\\s*(\\d+(?:\\.\\d+)?)/i', $text, $matches)) {
            return (float) $matches[1];
        }

        if (preg_match('/\\bfor\\s+(\\d+(?:\\.\\d+)?)\\b/i', $text, $matches)) {
            return (float) $matches[1];
        }

        return null;
    }

    protected function extractNameValue(string $text): ?string
    {
        if (preg_match('/\\b(?:called|named)\\s+([\\w\\-\\s]+?)(?:\\s+for\\b|\\s+at\\b|[\\.,!?]|$)/i', $text, $matches)) {
            return trim($matches[1]);
        }

        if (preg_match('/\\b(?:an|a)\\s+([\\w\\-\\s]{2,})$/i', trim($text), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
    
    /**
     * Find missing required fields
     */
    protected function findMissingFields(array $extracted, array $actionDefinition): array
    {
        $required = $actionDefinition['required_params'] ?? [];
        $missing = [];
        
        foreach ($required as $field) {
            if (empty($extracted[$field])) {
                $missing[] = $field;
            }
        }
        
        // Check model's AI config for additional required and critical fields
        $modelClass = $actionDefinition['model_class'] ?? null;
        if ($modelClass && class_exists($modelClass)) {
            try {
                $reflection = new \ReflectionClass($modelClass);
                
                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    if ($method->isStatic()) {
                        $config = $modelClass::initializeAI();
                        
                        // Check critical fields (must be present)
                        $criticalFields = $config['critical_fields'] ?? [];
                        foreach ($criticalFields as $fieldName => $fieldConfig) {
                            if (empty($extracted[$fieldName])) {
                                if (!in_array($fieldName, $missing)) {
                                    $missing[] = $fieldName;
                                }
                            }
                        }
                        
                        // Check required fields
                        $fields = $config['fields'] ?? [];
                        foreach ($fields as $fieldName => $fieldConfig) {
                            if (($fieldConfig['required'] ?? false) && empty($extracted[$fieldName])) {
                                if (!in_array($fieldName, $missing)) {
                                    $missing[] = $fieldName;
                                }
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                // Ignore errors
            }
        }
        
        return $missing;
    }
    
    /**
     * Smart merge parameters - move standalone fields into array structures
     */
    protected function smartMergeParameters(array $params, array $actionDefinition): array
    {
        // Convention-based merging using model's item_structure
        $params = $this->conventionBasedMerge($params, $actionDefinition);
        
        // Config-based merging for models with alternative_fields
        $params = $this->configBasedMerge($params, $actionDefinition);
        
        return $params;
    }
    
    /**
     * Convention-based merge for common patterns (items, price, quantity, etc.)
     */
    protected function conventionBasedMerge(array $params, array $actionDefinition = []): array
    {
        // Find array fields in the action definition
        $fields = $actionDefinition['parameters']['fields'] ?? [];
        
        foreach ($fields as $arrayFieldName => $fieldConfig) {
            // Check if this is an array field with item_structure
            if (($fieldConfig['type'] ?? null) !== 'array') {
                continue;
            }
            
            // Skip if the array field doesn't exist in params
            if (!isset($params[$arrayFieldName]) || !is_array($params[$arrayFieldName]) || empty($params[$arrayFieldName])) {
                continue;
            }
            
            // Get the item structure to know which fields belong inside the array
            $itemStructure = $fieldConfig['item_structure'] ?? [];
            if (empty($itemStructure)) {
                continue;
            }
            
            // Extract field names from item_structure
            $itemFields = array_keys($itemStructure);
            $toMerge = [];
            
            // Check if any of these fields exist at root level
            foreach ($itemFields as $field) {
                if (isset($params[$field]) && !is_array($params[$field])) {
                    $toMerge[$field] = $params[$field];
                    unset($params[$field]);
                }
            }
            
            if (!empty($toMerge)) {
                // Merge into first item
                $params[$arrayFieldName][0] = array_merge(
                    $params[$arrayFieldName][0] ?? [], 
                    $toMerge
                );
                
                Log::channel('ai-engine')->debug('Convention-based merge applied', [
                    'merged_fields' => array_keys($toMerge),
                    'into' => "{$arrayFieldName}[0]",
                    'from_structure' => $itemStructure ? 'model config' : 'none',
                ]);
            }
        }
        
        return $params;
    }
    
    /**
     * Config-based merge using alternative_fields from model definition
     */
    protected function configBasedMerge(array $params, array $actionDefinition): array
    {
        $fields = $actionDefinition['parameters']['fields'] ?? [];
        
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!isset($fieldConfig['alternative_fields']) || !is_array($fieldConfig['alternative_fields'])) {
                continue;
            }
            
            // Check if any alternative fields exist at root level
            $alternativeData = [];
            foreach ($fieldConfig['alternative_fields'] as $altField) {
                if (isset($params[$altField])) {
                    $alternativeData[$altField] = $params[$altField];
                    unset($params[$altField]);
                }
            }
            
            if (!empty($alternativeData)) {
                // Initialize array if not exists
                if (!isset($params[$fieldName])) {
                    $params[$fieldName] = [];
                }
                
                if (empty($params[$fieldName])) {
                    // Create new item with alternative data
                    $params[$fieldName][] = $alternativeData;
                } else {
                    // Merge into first item
                    $params[$fieldName][0] = array_merge(
                        $params[$fieldName][0] ?? [],
                        $alternativeData
                    );
                }
                
                Log::channel('ai-engine')->debug('Config-based merge applied', [
                    'merged_fields' => array_keys($alternativeData),
                    'into' => "{$fieldName}[0]",
                ]);
            }
        }
        
        return $params;
    }
    
    /**
     * Calculate extraction confidence based on how well the action matches the message
     */
    protected function calculateConfidence(array $extracted, array $actionDefinition): float
    {
        $required = $actionDefinition['required_params'] ?? [];
        $optional = $actionDefinition['optional_params'] ?? [];
        $totalFields = count($required) + count($optional);
        
        // If action has no fields defined, it's a poor match (low confidence)
        // Actions should define their fields to be considered relevant
        if ($totalFields === 0) {
            // Return very low confidence if nothing was extracted
            // This prevents actions with no field definitions from winning
            return empty($extracted) ? 0.1 : 0.5;
        }
        
        $extractedCount = count($extracted);
        $requiredCount = count($required);
        $requiredExtracted = 0;
        
        foreach ($required as $field) {
            if (!empty($extracted[$field])) {
                $requiredExtracted++;
            }
        }
        
        // If nothing was extracted, this action is not relevant
        if ($extractedCount === 0) {
            return 0.0;
        }
        
        // Required fields have more weight
        $requiredWeight = 0.7;
        $optionalWeight = 0.3;
        
        $requiredScore = $requiredCount > 0 ? ($requiredExtracted / $requiredCount) : 1.0;
        $optionalScore = $totalFields > $requiredCount ? 
            (($extractedCount - $requiredExtracted) / ($totalFields - $requiredCount)) : 0.0;
        
        $confidence = ($requiredScore * $requiredWeight) + ($optionalScore * $optionalWeight);

        if ($requiredCount > 0 && $requiredExtracted === $requiredCount) {
            $confidence = max($confidence, 0.8);
        }
        
        return round($confidence, 2);
    }
}

/**
 * Extraction Result DTO
 */
class ExtractionResult
{
    public function __construct(
        public array $params,
        public array $missing,
        public float $confidence,
        public ?int $durationMs = null
    ) {}
    
    public function isComplete(): bool
    {
        return empty($this->missing);
    }
    
    public function hasHighConfidence(): bool
    {
        return $this->confidence >= 0.8;
    }
    
    public function toArray(): array
    {
        return [
            'params' => $this->params,
            'missing' => $this->missing,
            'confidence' => $this->confidence,
            'is_complete' => $this->isComplete(),
            'duration_ms' => $this->durationMs,
        ];
    }
}
