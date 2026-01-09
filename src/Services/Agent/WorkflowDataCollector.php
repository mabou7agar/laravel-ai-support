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
    ) {}
    
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
     * Collect data progressively from user messages
     * 
     * @param UnifiedActionContext $context
     * @param array $fieldDefinitions
     * @return ActionResult
     */
    public function collectData(
        UnifiedActionContext $context,
        array $fieldDefinitions
    ): ActionResult {
        // Get existing collected data
        $collectedData = $context->get('collected_data', []);
        
        \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('WorkflowDataCollector: Starting data collection', [
            'has_collected_data' => !empty($collectedData),
            'collected_data' => $collectedData,
            'conversation_history_count' => count($context->conversationHistory ?? []),
            'conversation_history' => $context->conversationHistory ?? [],
            'workflow_state_keys' => array_keys($context->workflowState ?? []),
        ]);
        
        // Extract from conversation history
        if (!empty($context->conversationHistory)) {
            // If we have existing collected data, only extract from latest message
            // Otherwise, extract from all messages to capture initial request
            if (!empty($collectedData)) {
                // Extract from latest message only (we already have some data)
                $lastMessage = end($context->conversationHistory);
                $userMessage = $lastMessage['content'] ?? '';
                
                \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('WorkflowDataCollector: Extracting from latest message', [
                    'user_message' => $userMessage,
                    'has_message' => !empty($userMessage),
                    'existing_data_keys' => array_keys($collectedData),
                ]);
                
                if (!empty($userMessage)) {
                    $newData = $this->extractDataFromMessage(
                        $userMessage,
                        $fieldDefinitions,
                        $collectedData
                    );
                    // Merge new data with existing data (preserving existing values)
                    $collectedData = $this->mergeData($collectedData, $newData);
                }
            } else {
                // First collection - extract from all messages to capture initial request
                \Illuminate\Support\Facades\Log::channel('ai-engine')->debug('WorkflowDataCollector: First collection, extracting from all messages', [
                    'message_count' => count($context->conversationHistory),
                ]);
                
                foreach ($context->conversationHistory as $msg) {
                    if ($msg['role'] === 'user') {
                        $newData = $this->extractDataFromMessage(
                            $msg['content'],
                            $fieldDefinitions,
                            $collectedData
                        );
                        $collectedData = $this->mergeData($collectedData, $newData);
                    }
                }
            }
        }
        
        // Save collected data
        $context->set('collected_data', $collectedData);
        
        // Check what's still missing
        $missing = $this->getMissingFields($collectedData, $fieldDefinitions);
        
        if (!empty($missing)) {
            // Ask for the first missing field
            $firstMissing = $missing[0];
            $fieldDef = $fieldDefinitions[$firstMissing];
            
            $prompt = $fieldDef['prompt'] ?? "Please provide {$firstMissing}";
            
            return ActionResult::needsUserInput(
                message: $prompt,
                data: [
                    'collected' => $collectedData,
                    'missing' => $missing,
                    'asking_for' => $firstMissing,
                ]
            );
        }
        
        // All data collected
        return ActionResult::success(
            message: 'All data collected',
            data: $collectedData
        );
    }
    
    /**
     * Extract data from user message using AI
     */
    protected function extractDataFromMessage(
        string $message,
        array $fieldDefinitions,
        array $existingData
    ): array {
        // Build prompt for AI
        $prompt = "Extract structured data from the user's message.\n\n";
        $prompt .= "User said: \"{$message}\"\n\n";
        
        if (!empty($existingData)) {
            $prompt .= "Already collected: " . json_encode($existingData) . "\n\n";
        }
        
        $prompt .= "Fields to extract:\n";
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            $type = $fieldDef['type'] ?? 'string';
            $description = $fieldDef['description'] ?? $fieldName;
            $prompt .= "- {$fieldName} ({$type}): {$description}\n";
        }
        
        $prompt .= "\nRules:\n";
        $prompt .= "- Only extract fields that are clearly mentioned in the message\n";
        $prompt .= "- Return empty object {} if no fields can be extracted\n";
        $prompt .= "- For numeric fields, extract only numbers\n";
        $prompt .= "- For arrays, extract as array of objects\n";
        $prompt .= "- If user says 'remove', 'delete', or 'take out' an item, add '_remove' suffix to mark for removal\n";
        $prompt .= "- If user modifies quantity of existing item, extract with new quantity\n";
        $prompt .= "- Don't guess or infer data not explicitly stated\n\n";
        
        $prompt .= "Return ONLY valid JSON with extracted fields. Example:\n";
        $prompt .= '{"customer_name": "John Smith", "products": [{"name": "iPhone", "quantity": 5}], "products_remove": ["MacBook"]}';
        
        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 300,
                temperature: 0
            ));
            
            $extracted = json_decode($response->content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            
            return $extracted ?? [];
            
        } catch (\Exception $e) {
            \Log::channel('ai-engine')->error('Data extraction failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Merge new data with existing data
     */
    protected function mergeData(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            // Handle removal requests (e.g., products_remove)
            if (str_ends_with($key, '_remove')) {
                $baseKey = str_replace('_remove', '', $key);
                if (isset($existing[$baseKey]) && is_array($existing[$baseKey])) {
                    // Remove items from array
                    $existing[$baseKey] = $this->removeItems($existing[$baseKey], $value);
                }
                continue;
            }
            
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                // For arrays of objects (like products), merge intelligently
                if ($this->isArrayOfObjects($value)) {
                    $existing[$key] = $this->mergeArrayOfObjects($existing[$key], $value);
                } else {
                    // Simple array merge
                    $existing[$key] = array_merge($existing[$key], $value);
                }
            } else {
                // Overwrite with new value
                $existing[$key] = $value;
            }
        }
        
        return $existing;
    }
    
    /**
     * Check if array contains objects (associative arrays)
     */
    protected function isArrayOfObjects(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        
        $first = reset($array);
        return is_array($first) && array_keys($first) !== range(0, count($first) - 1);
    }
    
    /**
     * Merge array of objects intelligently (update existing, add new)
     */
    protected function mergeArrayOfObjects(array $existing, array $new): array
    {
        foreach ($new as $newItem) {
            $found = false;
            
            // Try to find matching item by name
            foreach ($existing as &$existingItem) {
                if (isset($newItem['name']) && isset($existingItem['name'])) {
                    if (strtolower($newItem['name']) === strtolower($existingItem['name'])) {
                        // Update existing item
                        $existingItem = array_merge($existingItem, $newItem);
                        $found = true;
                        break;
                    }
                }
            }
            
            // If not found, add as new item
            if (!$found) {
                $existing[] = $newItem;
            }
        }
        
        return $existing;
    }
    
    /**
     * Remove items from array based on removal list
     */
    protected function removeItems(array $items, array $toRemove): array
    {
        return array_values(array_filter($items, function($item) use ($toRemove) {
            if (is_array($item) && isset($item['name'])) {
                // Check if item name matches any removal request
                foreach ($toRemove as $removeItem) {
                    $removeName = is_array($removeItem) ? ($removeItem['name'] ?? $removeItem) : $removeItem;
                    if (stripos($item['name'], $removeName) !== false) {
                        return false; // Remove this item
                    }
                }
            }
            return true; // Keep this item
        }));
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
     * Validate collected data against field definitions
     */
    public function validateData(array $data, array $fieldDefinitions): array
    {
        $errors = [];
        
        foreach ($fieldDefinitions as $fieldName => $fieldDef) {
            $value = $data[$fieldName] ?? null;
            $required = $fieldDef['required'] ?? false;
            $type = $fieldDef['type'] ?? 'string';
            
            // Check required
            if ($required && empty($value)) {
                $errors[$fieldName] = "{$fieldName} is required";
                continue;
            }
            
            if ($value === null) {
                continue;
            }
            
            // Check type
            switch ($type) {
                case 'integer':
                case 'int':
                    if (!is_numeric($value)) {
                        $errors[$fieldName] = "{$fieldName} must be a number";
                    }
                    break;
                    
                case 'array':
                    if (!is_array($value)) {
                        $errors[$fieldName] = "{$fieldName} must be an array";
                    }
                    break;
                    
                case 'string':
                    if (!is_string($value) && !is_numeric($value)) {
                        $errors[$fieldName] = "{$fieldName} must be a string";
                    }
                    break;
            }
            
            // Check min/max
            if (isset($fieldDef['min']) && is_numeric($value)) {
                if ($value < $fieldDef['min']) {
                    $errors[$fieldName] = "{$fieldName} must be at least {$fieldDef['min']}";
                }
            }
            
            if (isset($fieldDef['max']) && is_numeric($value)) {
                if ($value > $fieldDef['max']) {
                    $errors[$fieldName] = "{$fieldName} must be at most {$fieldDef['max']}";
                }
            }
        }
        
        return $errors;
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
     * Check if all required fields are collected
     */
    public function isComplete(UnifiedActionContext $context, array $fieldDefinitions): bool
    {
        $collectedData = $context->get('collected_data', []);
        $missing = $this->getMissingFields($collectedData, $fieldDefinitions);
        return empty($missing);
    }
    
    /**
     * Clear collected data
     */
    public function clear(UnifiedActionContext $context): void
    {
        $context->set('collected_data', []);
    }
}
