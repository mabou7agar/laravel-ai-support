<?php

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * Generic AI-Powered Data Collector for Workflows
 * 
 * Similar to DataCollector but designed for complex workflows with:
 * - AI-powered data extraction from natural language
 * - Progressive data collection across multiple turns
 * - Conditional logic support
 * - Flexible field definitions
 */
class WorkflowDataCollector
{
    public function __construct(
        protected AIEngineService $ai
    ) {
        // Services instantiated on-demand to avoid circular dependencies
    }
    
    /**
     * Define fields to collect
     * 
     * @param array $fields Field definitions
     * Example:
     * [
     *     'customer_name' => [
     *         'type' => 'string',
     *         'required' => true,
     *         'prompt' => 'What is the customer name?',
     *     ],
     *     'quantity' => [
     *         'type' => 'integer',
     *         'required' => true,
     *         'prompt' => 'How many units?',
     *     ],
     * ]
     */
    public function defineFields(array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }
    
    /**
     * Collect data from user messages
     */
    public function collectData(UnifiedActionContext $context, array $fieldDefinitions, $workflow = null): ActionResult
    {
        try {
            $collectedData = $context->get('collected_data', []);
            
            // Try to extract data from conversation history
            if (!empty($context->conversationHistory)) {
                $askingFor = $context->get('asking_for');
                
                // Get the latest user message
                $lastMessage = null;
                foreach (array_reverse($context->conversationHistory) as $msg) {
                    if (($msg['role'] ?? '') === 'user') {
                        $lastMessage = $msg['content'] ?? '';
                        break;
                    }
                }
                
                if (!empty($lastMessage)) {
                    try {
                        $newData = $this->extractDataFromMessage(
                            $lastMessage,
                            $fieldDefinitions,
                            $collectedData,
                            $askingFor
                        );
                        
                        if (!empty($newData)) {
                            $collectedData = array_merge($collectedData, $newData);
                            $context->set('collected_data', $collectedData);
                        }
                    } catch (\Exception $e) {
                        // Continue on extraction error
                    }
                }
            }
            
            // Determine what's still missing
            $missing = $this->getMissingFields($collectedData, $fieldDefinitions);
            
            if (!empty($missing)) {
                $firstMissing = $missing[0];
                $fieldDef = $fieldDefinitions[$firstMissing];
                $prompt = $fieldDef['prompt'] ?? $fieldDef['description'] ?? "Please provide {$firstMissing}";
                
                // If this is an entity field, check if a suggestion was pre-generated
                if (($fieldDef['type'] ?? '') === 'entity') {
                    $suggestion = $context->get("_suggestion_{$firstMissing}");
                    
                    if ($suggestion) {
                        // Append suggestion to the prompt
                        $prompt .= " I suggest: {$suggestion}";
                        
                        \Illuminate\Support\Facades\Log::info('Using pre-generated suggestion for field', [
                            'field' => $firstMissing,
                            'suggestion' => $suggestion,
                        ]);
                    }
                }
                
                $context->set('asking_for', $firstMissing);
                
                return ActionResult::needsUserInput(
                    message: $prompt,
                    data: [
                        'collected' => $collectedData,
                        'missing' => $missing,
                        'asking_for' => $firstMissing,
                    ]
                );
            }
            
            return ActionResult::success(
                message: 'All data collected',
                data: $collectedData
            );
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: "Data collection failed: {$e->getMessage()}"
            );
        }
    }
    
    /**
     * Extract data from user message using AI with intelligent prompting
     */
    protected function extractDataFromMessage(
        string $message,
        array $fieldDefinitions,
        array $existingData,
        ?string $askingFor = null
    ): array {
        // If we're asking for a specific field, extract only that field
        if (!empty($askingFor) && isset($fieldDefinitions[$askingFor])) {
            $fieldDef = $fieldDefinitions[$askingFor];
            $type = $fieldDef['type'] ?? 'string';
            $description = $fieldDef['description'] ?? $askingFor;
            
            // OPTIMIZATION: For short, simple messages, use direct extraction
            $trimmedMessage = trim($message);
            $isSimpleMessage = strlen($trimmedMessage) < 100 && 
                              !str_contains($trimmedMessage, ' is ') && 
                              !str_contains($trimmedMessage, ':');
            
            if ($isSimpleMessage) {
                // Numeric types: extract first number
                if (in_array($type, ['integer', 'float', 'number'])) {
                    if (preg_match('/\d+(\.\d+)?/', $trimmedMessage, $matches)) {
                        return [$askingFor => $type === 'integer' ? (int)$matches[0] : (float)$matches[0]];
                    }
                    return [];
                }
                
                // String types: use entire message
                return [$askingFor => $trimmedMessage];
            }
            
            // Build AI extraction prompt for specific field
            $prompt = "Extract the value for '{$askingFor}' from the user's message.\n\n";
            $prompt .= "User message: {$message}\n\n";
            $prompt .= "Field: {$askingFor} ({$type}): {$description}\n\n";
            $prompt .= "Rules:\n";
            $prompt .= "- Extract ONLY the value for '{$askingFor}'\n";
            $prompt .= "- Preserve the COMPLETE value exactly as provided\n";
            $prompt .= "- For string fields, keep the full text\n";
            $prompt .= "- For numeric fields, extract only the number\n";
            $prompt .= "- Return empty {} if the message doesn't contain this field\n\n";
            $prompt .= "Return ONLY valid JSON. Example: {\"$askingFor\": \"value\"}";
        } else {
            // Extract all fields
            $prompt = "Extract structured data from the user's message.\n\n";
            $prompt .= "User message: {$message}\n\n";
            $prompt .= "FIELDS TO EXTRACT:\n";
            
            $extractableFields = $this->filterExtractableFields($fieldDefinitions);
            
            foreach ($extractableFields as $fieldName => $fieldDef) {
                $type = $fieldDef['type'] ?? 'string';
                $description = $fieldDef['description'] ?? $fieldName;
                $prompt .= "- {$fieldName} ({$type}): {$description}\n";
            }
            
            $prompt .= "\nRules:\n";
            $prompt .= "- Extract values EXACTLY as typed - preserve complete strings\n";
            $prompt .= "- For string fields: copy the ENTIRE string exactly\n";
            $prompt .= "- For numeric fields: extract only the number\n";
            $prompt .= "- Return empty {} if no data to extract\n\n";
            $prompt .= "Return ONLY valid JSON.";
        }
        
        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 300,
                temperature: 0
            ));
            
            $extracted = json_decode($response->content, true);
            
            return (json_last_error() === JSON_ERROR_NONE) ? ($extracted ?? []) : [];
            
        } catch (\Exception $e) {
            return [];
        }
    }
    
    /**
     * Merge new data with existing data using simple array_merge
     */
    protected function mergeData(array $existing, array $new): array
    {
        // Simple merge: new values override existing
        return array_merge($existing, $new);
    }
    
    
    /**
     * Get list of missing required fields
     */
    protected function getMissingFields(array $collectedData, array $fieldDefinitions): array
    {
        $missing = [];
        
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            $required = $fieldDef['required'] ?? false;
            
            if ($required && empty($collectedData[$fieldName])) {
                $missing[] = $fieldName;
            }
        }
        
        return $missing;
    }
    
    /**
     * Get field value from collected data
     */
    public function getField(UnifiedActionContext $context, string $fieldName, $default = null)
    {
        $collectedData = $context->get('collected_data', []);
        return $collectedData[$fieldName] ?? $default;
    }
    
    /**
     * Set field value in collected data
     */
    public function setField(UnifiedActionContext $context, string $fieldName, $value): void
    {
        $collectedData = $context->get('collected_data', []);
        $collectedData[$fieldName] = $value;
        $context->set('collected_data', $collectedData);
    }
    
    /**
     * Clear collected data
     */
    public function clear(UnifiedActionContext $context): void
    {
        $context->forget('collected_data');
        $context->forget('asking_for');
    }
    
    /**
     * Filter out entity fields that should not be auto-extracted
     * 
     * Entity fields with subflows or identifierProviders should be resolved
     * through their dedicated resolution steps, not auto-suggested during data collection.
     * This prevents the AI from auto-populating categories, customers, etc. before
     * the user is asked for them.
     * 
     * @param array $fieldDefinitions
     * @return array Filtered field definitions safe for AI extraction
     */
    protected function filterExtractableFields(array $fieldDefinitions): array
    {
        $extractableFields = [];
        
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            // Skip if not an array (simple string definitions are always extractable)
            if (!is_array($fieldDef)) {
                $extractableFields[$fieldName] = $fieldDef;
                continue;
            }
            
            // Check if this is an entity field with special resolution
            $hasSubflow = !empty($fieldDef['subflow']);
            $hasIdentifierProvider = !empty($fieldDef['identifierProvider']) || !empty($fieldDef['identifier_provider']);
            $isEntityType = ($fieldDef['type'] ?? '') === 'entity';
            
            // Skip entity fields that have subflows or identifierProviders
            // These should be resolved through dedicated steps, not auto-extracted
            if ($isEntityType && ($hasSubflow || $hasIdentifierProvider)) {
                \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('Filtering entity field from extraction', [
                    'field' => $fieldName,
                    'has_subflow' => $hasSubflow,
                    'has_identifier_provider' => $hasIdentifierProvider,
                    'reason' => 'Entity field with special resolution should not be auto-extracted',
                ]);
                continue;
            }
            
            // Include this field in extraction
            $extractableFields[$fieldName] = $fieldDef;
        }
        
        return $extractableFields;
    }
}
