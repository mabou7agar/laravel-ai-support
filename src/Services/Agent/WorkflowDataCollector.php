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

            // Check if we have an initial workflow message to extract from
            $initialMessage = $context->get('_initial_workflow_message');
            $extractionDone = $context->get('_initial_extraction_done', false);

            if (!empty($initialMessage) && !$extractionDone) {
                try {
                    // Use AI to extract all fields from initial message (same as normal flow)
                    $extractedData = $this->extractDataFromMessage(
                        $initialMessage,
                        $fieldDefinitions,
                        $collectedData,
                        null // Extract all fields from initial message
                    );

                    if (!empty($extractedData)) {
                        \Illuminate\Support\Facades\Log::info('WorkflowDataCollector: Initial extraction completed', [
                            'extracted_fields' => array_keys($extractedData),
                            'extracted_data' => $extractedData,
                        ]);
                        $collectedData = array_merge($collectedData, $extractedData);
                        $context->set('collected_data', $collectedData);
                    }

                    // Mark extraction as done
                    $context->set('_initial_extraction_done', true);
                } catch (\Exception $e) {
                    $context->set('_initial_extraction_done', true);
                }
            }

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
                    \Illuminate\Support\Facades\Log::info('WorkflowDataCollector: Attempting extraction from conversation', [
                        'message' => $lastMessage,
                        'asking_for' => $askingFor,
                        'field_definitions' => array_keys($fieldDefinitions),
                    ]);
                    
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
                
                // If prompt is a Closure, evaluate it with context
                if ($prompt instanceof \Closure) {
                    $prompt = $prompt($context);
                }

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
                $isArray = ($fieldDef['multiple'] ?? false) || ($fieldDef['is_array'] ?? false);
                $parsingGuide = $fieldDef['parsing_guide'] ?? null;
                
                if ($isArray) {
                    $prompt .= "- {$fieldName} (MUST BE ARRAY - NEVER STRING): {$description}\n";
                    
                    // Use parsing guide from config if available
                    if ($parsingGuide) {
                        $prompt .= "  {$parsingGuide}\n";
                    } else {
                        $prompt .= "  REQUIRED: Parse into array of objects with 'name' and 'quantity' fields\n";
                        $prompt .= "  Example: \"2 laptops and 3 mice\" MUST become:\n";
                        $prompt .= "  [{\"name\":\"laptops\",\"quantity\":2},{\"name\":\"mice\",\"quantity\":3}]\n";
                    }
                    $prompt .= "  CRITICAL: Do NOT return a string. MUST be array format.\n";
                } else {
                    $prompt .= "- {$fieldName} ({$type}): {$description}\n";
                    if ($parsingGuide) {
                        $prompt .= "  {$parsingGuide}\n";
                    }
                }
            }

            $prompt .= "\nCRITICAL RULES:\n";
            $prompt .= "1. ARRAY fields: MUST return as [{\"name\":\"...\",\"quantity\":N},...] format\n";
            $prompt .= "2. NEVER return array fields as strings\n";
            $prompt .= "3. Parse \"2 X and 3 Y\" as TWO separate array items\n";
            $prompt .= "4. Extract quantity numbers from text\n";
            $prompt .= "5. Return empty {} only if NO data can be extracted\n\n";
            $prompt .= "Return ONLY valid JSON with correct array structures.";
        }

        try {
            // Use the same AI service that's already configured
            // Include userId from auth for credit checking
            $userId = auth()->check() ? (string) auth()->id() : null;

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 300,
                temperature: 0,
                userId: $userId
            ));

            $content = $response->getContent();

            // Remove markdown code blocks if present (AI sometimes wraps JSON in ```json...```)
            $content = preg_replace('/^```json\s*\n?/m', '', $content);
            $content = preg_replace('/\n?```\s*$/m', '', $content);
            $content = trim($content);

            $extracted = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                \Illuminate\Support\Facades\Log::warning('WorkflowDataCollector: JSON decode failed', [
                    'content' => $content,
                    'error' => json_last_error_msg(),
                ]);
                return [];
            }
            
            \Illuminate\Support\Facades\Log::info('WorkflowDataCollector: Extraction result', [
                'extracted' => $extracted,
                'fields' => array_keys($extracted ?? []),
            ]);

            // Validate array fields - if string returned instead of array, try custom parser
            foreach ($fieldDefinitions as $fieldName => $fieldDef) {
                $isArray = ($fieldDef['multiple'] ?? false) || ($fieldDef['is_array'] ?? false);
                
                if ($isArray && isset($extracted[$fieldName]) && is_string($extracted[$fieldName])) {
                    \Illuminate\Support\Facades\Log::warning('WorkflowDataCollector: Array field returned as string', [
                        'field' => $fieldName,
                        'value' => $extracted[$fieldName],
                        'has_custom_parser' => isset($fieldDef['custom_parser']),
                    ]);
                    
                    // Try custom parser if provided by workflow
                    if (isset($fieldDef['custom_parser']) && $fieldDef['custom_parser'] instanceof \Closure) {
                        try {
                            $parsed = $fieldDef['custom_parser']($extracted[$fieldName]);
                            if (is_array($parsed) && !empty($parsed)) {
                                \Illuminate\Support\Facades\Log::info('WorkflowDataCollector: Custom parser successfully parsed string', [
                                    'field' => $fieldName,
                                    'parsed_count' => count($parsed),
                                ]);
                                $extracted[$fieldName] = $parsed;
                                continue;
                            }
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::warning('WorkflowDataCollector: Custom parser failed', [
                                'field' => $fieldName,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    
                    // If no custom parser or parsing failed, clear the field
                    \Illuminate\Support\Facades\Log::warning('WorkflowDataCollector: Clearing string value from array field', [
                        'field' => $fieldName,
                    ]);
                    unset($extracted[$fieldName]);
                }
            }

            return $extracted ?? [];

        } catch (\Exception $e) {
            // AI extraction failed - return empty and let normal flow ask for the field
            return [];
        }
    }
        
    /**
     * Get missing required fields
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
