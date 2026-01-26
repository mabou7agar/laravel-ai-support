<?php

namespace LaravelAIEngine\Services\Agent\Traits;

use LaravelAIEngine\DTOs\WorkflowStep;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Enums\EntityState;
use Illuminate\Support\Facades\Log;

/**
 * Trait to automatically generate workflow steps from declarative configuration
 *
 * Usage:
 * class MyWorkflow extends AgentWorkflow
 * {
 *     use AutomatesSteps;
 *
 *     protected function config(): array
 *     {
 *         return [
 *             'goal' => 'Create an invoice',
 *             'fields' => [...],
 *             'entities' => [...],
 *             'final_action' => fn($ctx) => $this->createInvoice($ctx),
 *         ];
 *     }
 * }
 */
trait AutomatesSteps
{
    /**
     * Override defineSteps to auto-generate from config
     */
    public function defineSteps(): array
    {
        $config = $this->config();
        $steps = [];

        // Determine first entity step or final action
        $entities = $config['entities'] ?? [];
        $firstEntityName = !empty($entities) ? array_keys($entities)[0] : null;
        $nextStepAfterCollection = $firstEntityName ? "resolve_{$firstEntityName}" : 'execute_final_action';

        // 1. Data collection step
        // Note: Prefix is automatically applied by AgentWorkflow::applyStepPrefix() for subflows
        $steps[] = WorkflowStep::make('collect_data')
            ->description('Collect required information')
            ->execute(fn($ctx) => $this->autoCollectData($ctx, $config))
            ->onSuccess($nextStepAfterCollection)
            ->onFailure('error'); // Don't loop back - let AgentMode handle user input

        // 2. Entity resolution steps (auto-generated for each entity)
        $confirmBeforeComplete = $config['confirm_before_complete'] ?? false;
        if (!empty($entities)) {
            $entitySteps = $this->generateEntityResolutionSteps($entities, $confirmBeforeComplete);
            $steps = array_merge($steps, $entitySteps);
        }

        // 2.5. Collect missing fields for array items (e.g., prices for products)
        $nextStepAfterArrayCollection = $confirmBeforeComplete ? 'confirm_action' : 'execute_final_action';
        $steps[] = WorkflowStep::make('collect_array_item_fields')
            ->description('Collect missing fields for array items')
            ->execute(fn($ctx) => $this->collectMissingArrayItemFields($ctx, $config))
            ->onSuccess($nextStepAfterArrayCollection)
            ->onFailure('collect_array_item_fields');

        // 3. Confirmation step (if enabled)
        if ($confirmBeforeComplete) {
            $steps[] = WorkflowStep::make('confirm_action')
                ->description('Confirm before completing')
                ->execute(fn($ctx) => $this->confirmBeforeCompleteWithSubflowCheck($ctx, $config))
                ->onSuccess('execute_final_action')
                ->onFailure('confirm_action');
        }

        // 4. Final action step
        $steps[] = WorkflowStep::make('execute_final_action')
            ->description($config['goal'] ?? 'Complete the task')
            ->execute(fn($ctx) => $this->executeFinalAction($ctx, $config))
            ->onSuccess('complete')
            ->onFailure('error');

        return $steps;
    }

    /**
     * Auto-collect data based on field definitions
     * Enhanced with AI extraction from initial message
     */
    protected function autoCollectData(UnifiedActionContext $context, array $config): ActionResult
    {
        $fields = $config['fields'] ?? [];

        if (empty($fields)) {
            return ActionResult::success(message: 'No data to collect');
        }

        \Illuminate\Support\Facades\Log::info('AutomatesSteps: autoCollectData called', [
            'fields_count' => count($fields),
            'fields' => array_keys($fields),
            'has_entities' => !empty($config['entities']),
        ]);

        // Pre-generate suggestions for entity fields with identifierProvider
        // Store only the suggestion strings (not Closures) to avoid serialization errors
        // Also ensure 'multiple' and 'is_array' flags are set for entity fields
        if (!empty($config['entities'])) {
            foreach ($config['entities'] as $entityName => $entityConfig) {
                $identifierField = $entityConfig['identifier_field'] ?? $entityName;

                \Illuminate\Support\Facades\Log::info('AutomatesSteps: Processing entity', [
                    'entity' => $entityName,
                    'identifier_field' => $identifierField,
                    'is_multiple' => ($entityConfig['multiple'] ?? false),
                    'field_exists' => isset($fields[$identifierField]),
                ]);

                // Ensure 'multiple' and 'is_array' flags are set if entity is multiple
                if (($entityConfig['multiple'] ?? false)) {
                    if (isset($fields[$identifierField])) {
                        $fields[$identifierField]['multiple'] = true;
                        $fields[$identifierField]['is_array'] = true;

                        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Set multiple flags on field', [
                            'field' => $identifierField,
                            'entity' => $entityName,
                        ]);
                    } else {
                        \Illuminate\Support\Facades\Log::warning('AutomatesSteps: Field not found for multiple entity', [
                            'field' => $identifierField,
                            'entity' => $entityName,
                            'available_fields' => array_keys($fields),
                        ]);
                    }
                }

                // If this entity has an identifierProvider, call it now and store the result
                if (!empty($entityConfig['identifier_provider']) &&
                    $entityConfig['identifier_provider'] instanceof \Closure) {

                    try {
                        $suggestion = $entityConfig['identifier_provider']($context);
                        if ($suggestion) {
                            // Store the suggestion string (not the Closure) in context
                            $context->set("_suggestion_{$identifierField}", $suggestion);

                            \Illuminate\Support\Facades\Log::info('Pre-generated suggestion for entity field', [
                                'field' => $identifierField,
                                'suggestion' => $suggestion,
                            ]);
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::warning('Failed to pre-generate suggestion', [
                            'field' => $identifierField,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Use WorkflowDataCollector service directly for automatic data collection
        $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);
        $dataCollector = new \LaravelAIEngine\Services\Agent\WorkflowDataCollector($aiService);
        $result = $dataCollector->collectData($context, $fields);

        // If data collection is complete (success), we should proceed to next step
        if ($result->success && !$result->getMetadata('needs_user_input', false)) {
            return ActionResult::success(
                message: 'Data collection complete',
                data: $result->data
            );
        }

        return $result;

        // Enhanced: AI-powered extraction from initial message
        $collectedData = $context->get('collected_data', []);

        // If this is the first time collecting and we have conversation history, try AI extraction
        // Only attempt once to avoid infinite loops
        $hasAttemptedExtraction = $context->get('has_attempted_ai_extraction', false);
        if (empty($collectedData) && !empty($context->conversationHistory) && !$hasAttemptedExtraction) {
            $context->set('has_attempted_ai_extraction', true);
            $extractedData = $this->extractDataFromMessage($context, $fields);

            if (!empty($extractedData)) {
                // Map extracted data to expected field names
                $mappedData = $this->mapExtractedDataToFields($extractedData, $fields);

                // Filter out empty arrays for required fields (they should be prompted for)
                // But mark that we've attempted extraction to avoid infinite loops
                $attemptedExtraction = $context->get('attempted_ai_extraction', []);
                foreach ($mappedData as $key => $value) {
                    if (is_array($value) && empty($value)) {
                        // Mark that we attempted to extract this field but got empty result
                        $attemptedExtraction[$key] = true;
                        // Don't add empty arrays - let the workflow prompt for them
                        unset($mappedData[$key]);
                    }

                    // CRITICAL FIX: Convert strings to arrays for entity fields that expect multiple items
                    $fieldConfig = $fields[$key] ?? [];
                    if (is_string($value) && is_array($fieldConfig)) {
                        $fieldType = $fieldConfig['type'] ?? 'string';
                        $isMultiple = $fieldConfig['multiple'] ?? false;

                        // If field is entity type with multiple=true, convert string to array
                        if ($fieldType === 'entity' && $isMultiple) {
                            $mappedData[$key] = \LaravelAIEngine\Services\AI\FieldDetector::stringToItemArray($value, $fieldConfig);
                            \Illuminate\Support\Facades\Log::info('Auto-converted string to array during extraction', [
                                'field' => $key,
                                'original' => $value,
                                'converted' => $mappedData[$key],
                            ]);
                        }
                    }
                }
                $context->set('attempted_ai_extraction', $attemptedExtraction);

                // Merge mapped data with any existing collected data
                $collectedData = array_merge($collectedData, $mappedData);

                // CRITICAL: Normalize data types based on field configuration
                // Convert strings to arrays for entity fields with multiple=true
                $collectedData = $this->normalizeCollectedDataTypes($collectedData, $fields);

                $context->set('collected_data', $collectedData);

                \Illuminate\Support\Facades\Log::info('AI extracted data from message', [
                    'workflow' => class_basename($this),
                    'extracted_count' => count($extractedData),
                    'mapped_count' => count($mappedData),
                ]);
            }
        }

        // Check for missing fields (skip entity-type fields - they're handled in entity resolution step)
        $missingFields = [];
        foreach ($fields as $fieldName => $fieldConfig) {
            // Skip if already collected
            if (isset($collectedData[$fieldName])) {
                continue;
            }

            // Skip entity-type fields (they're handled in entity resolution step)
            if (($fieldConfig['type'] ?? '') === 'entity') {
                continue;
            }

            $missingFields[] = $fieldName;
        }

        if (!empty($missingFields)) {
            $field = reset($missingFields); // Get first element regardless of key
            $fieldConfig = $fields[$field] ?? [];

            return ActionResult::needsUserInput(
                message: $fieldConfig['prompt'] ?? "Please provide {$field}",
                metadata: [
                    'field' => $field,
                    'type' => $fieldConfig['type'] ?? 'string',
                ]
            );
        }

        return ActionResult::success(
            message: 'All data collected',
            data: $collectedData
        );
    }

    /**
     * Check if running as subflow and skip confirmation if configured
     */
    protected function confirmBeforeCompleteWithSubflowCheck(UnifiedActionContext $context, array $config): ActionResult
    {
        // Check if we should skip confirmation when running as subflow
        $skipInSubflow = $config['skip_confirmation_in_subflow'] ?? false;
        $activeSubflow = $context->get('active_subflow');

        if ($skipInSubflow && $activeSubflow) {
            // Skip confirmation and proceed directly to final action
            return ActionResult::success(message: 'Skipping confirmation in subflow context');
        }

        // Otherwise, show normal confirmation
        return $this->confirmBeforeComplete($context, $config);
    }

    /**
     * Show confirmation before completing the workflow
     * Can be overridden by workflows for custom confirmation messages
     */
    protected function confirmBeforeComplete(UnifiedActionContext $context, array $config): ActionResult
    {
        // Track if this is the first time showing confirmation
        $firstTimeShowing = !$context->get('confirmation_message_shown');

        // On first entry, clear any previous confirmation flags and mark that we're showing confirmation
        if ($firstTimeShowing) {
            $context->forget('user_confirmed_action');
            $context->set('confirmation_message_shown', true);
            $context->set('awaiting_confirmation', true); // Flag for language-agnostic intent detection
        } else {
            // On subsequent entries, check if user has confirmed
            if ($context->get('user_confirmed_action')) {
                $context->forget('user_confirmed_action');
                $context->forget('confirmation_message_shown');
                $context->forget('awaiting_confirmation');
                return ActionResult::success(message: 'Confirmed');
            }
        }

        // Get collected data to show summary
        $collectedData = $context->get('collected_data', []);
        $entities = $config['entities'] ?? [];

        // CRITICAL: Enrich entity data with includeFields before confirmation
        // This ensures entities are stored as objects with full data, not just IDs/strings
        $collectedData = $this->enrichEntitiesWithIncludeFields($collectedData, $entities, $context);
        $context->set('collected_data', $collectedData);

        // Prepare data for display
        $dataForDisplay = [];

        // Add collected data (skip fields that have corresponding entities)
        foreach ($collectedData as $key => $value) {
            // Skip entity IDs - we'll show entity names instead
            if (str_ends_with($key, '_id') && isset($entities[rtrim($key, '_id')])) {
                continue;
            }

            // Skip if this field has a corresponding entity (e.g., skip 'items' if we have 'products' entity)
            $hasEntity = false;
            foreach ($entities as $entityName => $entityConfig) {
                $identifierField = $entityConfig['identifier_field'] ?? $entityName;
                if ($key === $identifierField) {
                    $hasEntity = true;
                    break;
                }
            }

            if (!$hasEntity) {
                $dataForDisplay[$key] = $value;
            }
        }

        // Add entity information with resolved names
        foreach ($entities as $entityName => $entityConfig) {
            $entityIdKey = $entityName . '_id';
            $entityId = $context->get($entityIdKey);

            if ($entityId) {
                $modelClass = $entityConfig['model'] ?? null;

                if (is_array($entityId)) {
                    // For multiple entities, use entity name as single source of truth
                    // Data is stored under entity name (e.g., 'products') with all resolved fields
                    $entityData = $collectedData[$entityName] ?? [];
                    
                    // Fallback to identifier field for backward compatibility
                    if (empty($entityData)) {
                        $identifierField = $entityConfig['identifier_field'] ?? $entityName;
                        $entityData = $collectedData[$identifierField] ?? [];
                    }

                    $dataForDisplay[$entityName] = $entityData;
                } else {
                    // For single entity, check if enriched data exists in collected_data first
                    $entityData = $collectedData[$entityName] ?? null;

                    // Handle case where data is stored as array with one element [{...}]
                    if (is_array($entityData) && isset($entityData[0]) && is_array($entityData[0])) {
                        $entityData = $entityData[0];
                    }

                    if (is_array($entityData) && isset($entityData['id'])) {
                        // Use enriched data from collected_data
                        $dataForDisplay[$entityName] = $entityData['name'] ?? $entityData['title'] ?? $entityId;
                    } else {
                        // Fallback: get the name from database
                        $entityName_value = $entityId;
                        if ($modelClass && class_exists($modelClass)) {
                            try {
                                $entity = $modelClass::find($entityId);
                                if ($entity) {
                                    $entityName_value = $entity->name ?? $entity->title ?? $entity->label ?? $entityId;
                                }
                            } catch (\Exception $e) {
                                // Use ID as fallback
                            }
                        }
                        $dataForDisplay[$entityName] = $entityName_value;
                    }
                }
            }
        }

        // Log what data we're about to display
        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Data prepared for confirmation display', [
            'dataForDisplay' => $dataForDisplay,
            'collected_data' => $collectedData,
            'entities' => array_keys($entities),
        ]);

        // Create user-friendly confirmation message using AI enhancement
        $message = $this->enhanceConfirmationMessage($dataForDisplay, $config);

        $message .= "\n\nWould you like to proceed? Type 'yes' to confirm, 'no' to cancel";

        // Add context-aware modification hint if there's data that can be modified
        if (!empty($dataForDisplay)) {
            $message .= ", or tell me what to change";
        }

        // ONLY check for user response if this is NOT the first time showing confirmation
        if (!$firstTimeShowing) {
            $conversationHistory = $context->conversationHistory ?? [];
            if (!empty($conversationHistory)) {
                $lastUserMessage = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');
                if (!empty($lastUserMessage)) {
                    $lastMsg = end($lastUserMessage);
                    $userResponse = trim($lastMsg['content'] ?? '');

                    // Use IntentAnalysisService to determine if user wants to modify
                    $intentAnalysis = app(\LaravelAIEngine\Services\IntentAnalysisService::class)->analyzeMessageIntent($userResponse);
                    $isModification = in_array($intentAnalysis['intent'], ['modify', 'update', 'change', 'new_request']);
                    
                    // Also check if the message contains data that looks like a modification (e.g., "set price 600")
                    if (!$isModification && !empty($userResponse) && $intentAnalysis['intent'] !== 'confirm' && $intentAnalysis['intent'] !== 'reject') {
                        // If not a simple confirm/reject, treat as potential modification
                        $isModification = true;
                    }
                    
                    if ($isModification) {
                        // User wants to modify - apply to full collected_data, then extract changes to user_inputs
                        $collectedData = $context->get('collected_data', []);
                        $userInputs = $collectedData['user_inputs'] ?? [];
                        
                        // Build merged data for AI to modify (resolved data + user inputs)
                        // Exclude user_inputs key itself from the data sent to AI
                        $dataForModification = array_filter($collectedData, fn($k) => $k !== 'user_inputs', ARRAY_FILTER_USE_KEY);
                        
                        // Apply modification to merged data
                        $modifiedData = $this->applyAIModification($userResponse, $dataForModification, $config);
                        
                        if ($modifiedData !== null) {
                            // Extract user modifications (fields that changed)
                            foreach ($modifiedData as $key => $value) {
                                // Update main collected_data
                                $collectedData[$key] = $value;
                                
                                // Also store in user_inputs to preserve the modification
                                if (!isset($collectedData['user_inputs'])) {
                                    $collectedData['user_inputs'] = [];
                                }
                                $collectedData['user_inputs'][$key] = $value;
                            }
                            
                            $context->set('collected_data', $collectedData);

                            // Keep confirmation flags set so next "yes" proceeds directly
                            // Don't reset - the summary we show IS the confirmation
                            $context->set('confirmation_message_shown', true);
                            $context->set('awaiting_confirmation', true);

                            // Use workflow's confirmation formatting (same as regular confirmation)
                            $summary = $this->enhanceConfirmationMessage($collectedData, $config);
                            
                            // Prepend "Updated!" to the confirmation message
                            $message = "✅ **Updated!**\n\n" . $summary;
                            
                            return ActionResult::needsUserInput(
                                message: $message,
                                metadata: ['modification_applied' => true]
                            );
                        }
                    }

                    // Use AI to interpret confirmation intent (language-agnostic)
                    $intelligentService = app(\LaravelAIEngine\Services\IntelligentEntityService::class);
                    $intent = $intelligentService->interpretConfirmationIntent($userResponse);

                    if ($intent === 'confirm') {
                        $context->set('user_confirmed_action', true);
                        return ActionResult::success(message: 'Confirmed');
                    } elseif ($intent === 'cancel') {
                        // Clear confirmation flags to prevent infinite loop
                        $context->forget('confirmation_message_shown');
                        $context->forget('awaiting_confirmation');
                        $context->forget('user_confirmed_action');

                        return ActionResult::failure(
                            error: 'Action cancelled by user',
                            data: ['user_cancelled' => true]
                        );
                    }
                    // If intent is null/unclear, continue to show confirmation again
                }
            }
        }

        // Always return needsUserInput to pause and wait for confirmation
        return ActionResult::needsUserInput(
            message: $message,
            metadata: ['awaiting_confirmation' => true]
        );
    }

    /**
     * Apply modification to collected data based on user request
     */
    protected function applyModification(array $collectedData, array $modification, array $config): array
    {
        $action = $modification['action'] ?? null;
        $field = $modification['field'] ?? null;
        $value = $modification['value'] ?? null;
        $itemName = $modification['item_name'] ?? null;
        $itemField = $modification['item_field'] ?? null;

        Log::channel('ai-engine')->info('AutomatesSteps: Applying modification', [
            'action' => $action,
            'field' => $field,
            'item_name' => $itemName,
            'item_field' => $itemField,
            'value' => $value,
        ]);

        if ($action === 'remove' && $field && $itemName) {
            // Remove item from array field
            if (isset($collectedData[$field]) && is_array($collectedData[$field])) {
                $collectedData[$field] = array_values(array_filter(
                    $collectedData[$field],
                    fn($item) => !str_contains(strtolower($item['name'] ?? ''), strtolower($itemName))
                ));

                Log::channel('ai-engine')->info('AutomatesSteps: Removed item from array', [
                    'field' => $field,
                    'item_name' => $itemName,
                    'remaining_count' => count($collectedData[$field]),
                ]);
            }
        } elseif ($action === 'update_item_field' && $field && $itemName && $itemField && $value !== null) {
            // Update a specific field within an array item
            // Get field aliases from workflow config (prioritize workflow config over global)
            $fieldAliases = $config['field_aliases'] ?? config('ai-engine.workflow.field_aliases', []);

            // Get array field name mapping from workflow config (prioritize workflow config over global)
            $arrayFieldMap = $config['array_field_mapping'] ?? config('ai-engine.workflow.array_field_mapping', []);

            // Map the field name if needed
            $actualField = $arrayFieldMap[$field] ?? $field;

            if (isset($collectedData[$actualField]) && is_array($collectedData[$actualField])) {
                foreach ($collectedData[$actualField] as $index => $item) {
                    // Match item by name (case-insensitive partial match)
                    if (isset($item['name']) && str_contains(strtolower($item['name']), strtolower($itemName))) {
                        // Update the specific field
                        $collectedData[$actualField][$index][$itemField] = $value;

                        // Also update field aliases if configured
                        if (isset($fieldAliases[$itemField])) {
                            foreach ($fieldAliases[$itemField] as $alias) {
                                $collectedData[$actualField][$index][$alias] = $value;
                            }
                        }

                        Log::channel('ai-engine')->info('AutomatesSteps: Updated item field', [
                            'requested_field' => $field,
                            'actual_field' => $actualField,
                            'item_name' => $item['name'],
                            'item_field' => $itemField,
                            'new_value' => $value,
                            'aliases_updated' => $fieldAliases[$itemField] ?? [],
                        ]);
                        break; // Only update first match
                    }
                }
            } else {
                Log::channel('ai-engine')->warning('AutomatesSteps: Array field not found for modification', [
                    'requested_field' => $field,
                    'actual_field' => $actualField,
                    'available_fields' => array_keys($collectedData),
                ]);
            }
        } elseif ($action === 'add' && $field && $value) {
            // Add item to array field
            if (!isset($collectedData[$field])) {
                $collectedData[$field] = [];
            }
            if (is_array($collectedData[$field])) {
                $collectedData[$field][] = $value;

                Log::channel('ai-engine')->info('AutomatesSteps: Added item to array', [
                    'field' => $field,
                    'new_count' => count($collectedData[$field]),
                ]);
            }
        } elseif ($action === 'change' && $field) {
            // Change field value
            $collectedData[$field] = $value;

            Log::channel('ai-engine')->info('AutomatesSteps: Changed field value', [
                'field' => $field,
                'new_value' => $value,
            ]);
        }

        return $collectedData;
    }

    /**
     * Apply modification using AI to interpret user request and modify full JSON
     * Sends the complete collected data to AI and asks it to apply the user's modification
     */
    protected function applyAIModification(string $userRequest, array $collectedData, array $config): ?array
    {
        try {
            // Use AIEngineService directly to avoid workflow detection
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);
            
            // Build prompt with full JSON
            $jsonData = json_encode($collectedData, JSON_PRETTY_PRINT);
            
            // Get modification rules from workflow config or use generic defaults
            $modificationRules = $config['modification_rules'] ?? 
                "1. Only modify what the user explicitly requested\n" .
                "2. Preserve all other fields exactly as they are\n" .
                "3. Return valid JSON only, no explanations";
            
            $prompt = <<<PROMPT
You are a data modification assistant. The user wants to modify the following data:

Current Data (JSON):
```json
{$jsonData}
```

User Request: "{$userRequest}"

Apply the user's modification to the data and return ONLY the modified JSON.
Rules:
{$modificationRules}

Modified JSON:
PROMPT;

            $response = $aiService->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 1000,
                temperature: 0
            ));

            $content = $response->getContent() ?? '';
            
            // Extract JSON from response
            if (preg_match('/```(?:json)?\s*(\{.*?\}|\[.*?\])\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            // Try to parse JSON
            $modifiedData = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($modifiedData)) {
                Log::channel('ai-engine')->info('AutomatesSteps: AI modification applied', [
                    'user_request' => $userRequest,
                    'original_keys' => array_keys($collectedData),
                    'modified_keys' => array_keys($modifiedData),
                ]);
                
                return $modifiedData;
            }
            
            Log::channel('ai-engine')->warning('AutomatesSteps: AI modification returned invalid JSON', [
                'user_request' => $userRequest,
                'response' => substr($content, 0, 500),
            ]);
            
            // Fallback to old method
            $intelligentService = app(\LaravelAIEngine\Services\IntelligentEntityService::class);
            $modification = $intelligentService->interpretModificationRequest($userRequest, $collectedData);
            
            if ($modification) {
                return $this->applyModification($collectedData, $modification, $config);
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('AutomatesSteps: AI modification failed', [
                'error' => $e->getMessage(),
                'user_request' => $userRequest,
            ]);
            
            return null;
        }
    }

    /**
     * Format confirmation data before AI enhancement
     * Override this in your workflow for custom formatting logic
     */
    protected function formatConfirmationData(array $data, array $config): array
    {
        // Default: return data as-is
        // Workflows can override this to transform data before confirmation
        return $data;
    }

    /**
     * Enhance confirmation message using AI to make it user-friendly
     */
    protected function enhanceConfirmationMessage(array $data, array $config): string
    {
        if (empty($data)) {
            return "**Please confirm the following details:**\n\nNo data collected yet.";
        }

        // Allow workflow to format/transform data before confirmation
        $formattedData = $this->formatConfirmationData($data, $config);

        // Get confirmation format rules from config or use default
        $confirmationFormat = $config['confirmation_format'] ?? null;

        $prompt = "Convert the following data into a user-friendly confirmation message. Format it nicely with clear labels and readable values.\n\n";

        if ($confirmationFormat) {
            // Use workflow-specific format rules
            $prompt .= "FORMATTING RULES:\n{$confirmationFormat}\n\n";
        } else {
            // Use generic default rules
            $prompt .= "FORMATTING RULES:\n";
            $prompt .= "- Use bullet points for lists\n";
            $prompt .= "- Keep it concise and professional\n";
            $prompt .= "- Format prices with $ symbol\n\n";
        }

        // Log the data being sent for confirmation
        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Sending data for confirmation enhancement', [
            'formatted_data' => $formattedData,
        ]);

        $prompt .= "Data:\n" . json_encode($formattedData, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "IMPORTANT: Use the prices from the data provided. Do NOT look up prices from any database.\n\n";
        $prompt .= "Return ONLY the formatted confirmation message text, starting with '**Please confirm the following details:**'";

        try {
            // Use direct AI call instead of chat service to avoid workflow detection
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);

            $response = $aiService->generate(new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                maxTokens: 500,
                temperature: 0.3
            ));

            $content = $response->getContent() ?? null;

            if ($content && str_contains($content, 'confirm')) {
                return $content;
            }

            return $this->fallbackConfirmationMessage($formattedData);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AutomatesSteps: AI enhancement failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
            return $this->fallbackConfirmationMessage($formattedData);
        }
    }

    /**
     * Fallback confirmation message if AI enhancement fails
     */
    protected function fallbackConfirmationMessage(array $data): string
    {
        $message = "**Please confirm the following details:**\n\n";

        // Fields to skip - use patterns instead of hardcoded names
        $skipPatterns = ['collected_data'];

        foreach ($data as $key => $value) {
            // Skip internal fields using patterns
            $shouldSkip = false;
            foreach ($skipPatterns as $pattern) {
                if ($key === $pattern || preg_match('/' . $pattern . '/', $key)) {
                    $shouldSkip = true;
                    break;
                }
            }
            if ($shouldSkip) {
                continue;
            }

            // Dynamic label generation - remove _id suffix and capitalize
            $label = ucfirst(str_replace('_', ' ', preg_replace('/_id$/', '', $key)));

            if (is_array($value)) {
                // Handle array values (like products list)
                $message .= "**{$label}:**\n";
                $grandTotal = 0;

                foreach ($value as $item) {
                    if (is_array($item)) {
                        // Get field values - use defaults for display
                        $fieldNames = \LaravelAIEngine\Services\AI\FieldDetector::getFieldNames();
                        $productName = $item[$fieldNames['identifier']] ?? \LaravelAIEngine\Services\AI\FieldDetector::detectIdentifier($item) ?? 'Unknown';
                        $qty = $item[$fieldNames['quantity']] ?? 1;
                        $price = \LaravelAIEngine\Services\AI\FieldDetector::detectPrice($item);

                        if ($price !== null) {
                            // Calculate line total
                            $lineTotal = $qty * $price;
                            $grandTotal += $lineTotal;

                            // Format: Product × Qty @ $UnitPrice = $LineTotal
                            $itemStr = "{$productName} × {$qty} @ \${$price} = \${$lineTotal}";
                        } else {
                            // No price available
                            $itemStr = "{$productName} × {$qty}";
                        }

                        $message .= "  • {$itemStr}\n";
                    } else {
                        $message .= "  • {$item}\n";
                    }
                }

                // Show grand total if we have prices
                if ($grandTotal > 0) {
                    $message .= "\n**Total:** \${$grandTotal}\n";
                }
            } else {
                // Handle simple values
                $displayValue = $value;

                // Format prices - detect by pattern instead of hardcoded names
                if (is_numeric($value) && preg_match('/(price|cost|fee|rate|charge|amount)$/i', $key)) {
                    $displayValue = "\${$value}";
                }

                $message .= "**{$label}:** {$displayValue}\n";
            }
        }

        return $message;
    }

    /**
     * Handle context-aware modifications using AI
     * Automatically understands user intent to modify collected data
     */
    protected function handleContextAwareModification(UnifiedActionContext $context, string $userMessage, array $collectedData): ?ActionResult
    {
        if (empty($collectedData)) {
            return null;
        }

        // Build context for AI
        $dataContext = "Current data:\n";
        foreach ($collectedData as $key => $value) {
            if (is_array($value)) {
                $dataContext .= "- {$key}: " . json_encode($value) . "\n";
            } else {
                $dataContext .= "- {$key}: {$value}\n";
            }
        }

        $prompt = "{$dataContext}\nUser message: \"{$userMessage}\"\n\n";
        $prompt .= "Analyze the user's intent to modify the data. Respond with JSON:\n";
        $prompt .= "{\n";
        $prompt .= "  \"action\": \"update\" | \"remove\" | \"unknown\",\n";
        $prompt .= "  \"field_name\": \"exact field name from the data\",\n";
        $prompt .= "  \"new_value\": \"new value for the field\" | null (if removing or unknown)\n";
        $prompt .= "}\n\n";
        $prompt .= "If the intent is unclear, use action: \"unknown\"";

        try {
            $chatService = app(\LaravelAIEngine\Services\ChatService::class);

            $response = $chatService->processMessage(
                message: $prompt,
                sessionId: 'modification_' . uniqid(),
                userId: null,
                useMemory: false,
                useActions: false
            );

            $jsonResponse = json_decode($response->getContent(), true);

            if (!$jsonResponse || !isset($jsonResponse['action']) || $jsonResponse['action'] === 'unknown') {
                return null;
            }

            $action = $jsonResponse['action'];
            $fieldName = $jsonResponse['field_name'] ?? '';
            $newValue = $jsonResponse['new_value'] ?? null;

            // Validate field exists
            if (!isset($collectedData[$fieldName])) {
                return null;
            }

            // Handle the modification
            switch ($action) {
                case 'update':
                    if ($newValue !== null) {
                        $collectedData[$fieldName] = $newValue;
                        $context->set('collected_data', $collectedData);

                        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Data updated via AI', [
                            'field' => $fieldName,
                            'new_value' => $newValue,
                        ]);

                        // Re-show confirmation with updated data
                        return $this->confirmBeforeComplete($context, $this->config());
                    }
                    break;

                case 'remove':
                    unset($collectedData[$fieldName]);
                    $context->set('collected_data', $collectedData);

                    \Illuminate\Support\Facades\Log::info('AutomatesSteps: Data removed via AI', [
                        'field' => $fieldName,
                    ]);

                    // Re-show confirmation with updated data
                    return $this->confirmBeforeComplete($context, $this->config());
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AutomatesSteps: AI modification failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Extract data from user message using AI
     * Generic extraction similar to WorkflowDataCollector
     */
    protected function extractDataFromMessage(UnifiedActionContext $context, array $fields): array
    {
        // Get all user messages from conversation history
        $userMessages = array_filter(
            $context->conversationHistory ?? [],
            fn($msg) => ($msg['role'] ?? '') === 'user'
        );

        if (empty($userMessages)) {
            return [];
        }

        // Extract from all user messages to capture initial request
        $allMessages = array_map(fn($msg) => $msg['content'] ?? '', $userMessages);
        $combinedMessage = implode(' ', $allMessages);

        if (empty($combinedMessage)) {
            return [];
        }

        // Get config to access extraction example
        $config = method_exists($this, 'config') ? $this->config() : [];

        // Build simple extraction prompt - trust AI intelligence
        $prompt = "Extract structured data from the user's message.\n\n";
        $prompt .= "User said: \"{$combinedMessage}\"\n\n";

        $prompt .= "Fields to extract:\n";
        foreach ($fields as $fieldName => $fieldConfig) {
            // Skip entity-type fields - they're handled by entity resolution
            if (is_array($fieldConfig) && ($fieldConfig['type'] ?? '') === 'entity') {
                continue;
            }

            $type = is_array($fieldConfig) ? ($fieldConfig['type'] ?? 'string') : 'string';
            $description = is_array($fieldConfig)
                ? ($fieldConfig['description'] ?? $fieldConfig['prompt'] ?? $fieldName)
                : $fieldConfig;
            $prompt .= "- {$fieldName} ({$type}): {$description}\n";
        }

        // Add extraction example if provided in config
        if (!empty($config['extraction_example'])) {
            $prompt .= "\nExample output format:\n";
            $prompt .= $config['extraction_example'];
        } else {
            // Default example
            $prompt .= "\nExample output format:\n";
            $prompt .= '{"customer_id": "John Smith", "items": [{"product": "iPhone 15 Pro", "quantity": 2}, {"product": "AirPods", "quantity": 1}]}';
        }

        $prompt .= "\n\nReturn ONLY valid JSON with extracted fields.";

        try {
            // askAI expects array context, not UnifiedActionContext
            $response = $this->askAI($prompt, []);

            // Clean response - remove markdown code blocks if present
            $response = preg_replace('/```json\s*|\s*```/', '', $response);
            $response = trim($response);

            // Parse JSON response
            $extracted = json_decode($response, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
                \Illuminate\Support\Facades\Log::info('AI extracted data successfully', [
                    'workflow' => class_basename($this),
                    'field_count' => count($extracted),
                ]);
                return $extracted;
            }

            \Illuminate\Support\Facades\Log::warning('Failed to parse AI extraction response', [
                'response' => $response,
                'error' => json_last_error_msg(),
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('AI extraction failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Normalize extracted arrays - convert string arrays to object arrays
     * Handles cases where AI extracts ["Product1", "Product2"] instead of [{"product": "Product1"}, {"product": "Product2"}]
     */
    protected function normalizeExtractedArrays(array $extracted, array $fields): array
    {
        foreach ($extracted as $key => $value) {
            // Check if this is an array field
            if (!is_array($value) || empty($value)) {
                continue;
            }

            // Check if it's an array of strings (needs normalization)
            $isStringArray = true;
            foreach ($value as $item) {
                if (!is_string($item)) {
                    $isStringArray = false;
                    break;
                }
            }

            // Convert string array to object array
            if ($isStringArray) {
                $fieldConfig = $fields[$key] ?? [];
                $configArray = is_array($fieldConfig) ? $fieldConfig : [];
                
                $normalizedItems = [];
                foreach ($value as $item) {
                    $normalizedItems[] = \LaravelAIEngine\Services\AI\FieldDetector::stringToArrayItem($item, $configArray);
                }
                $extracted[$key] = $normalizedItems;

                \Illuminate\Support\Facades\Log::info('Normalized string array to object array', [
                    'field' => $key,
                    'original_count' => count($value),
                    'normalized_count' => count($normalizedItems),
                ]);
            }
        }

        return $extracted;
    }

    /**
     * Map extracted data to expected field names
     * Handles cases where AI returns different field names than expected
     */
    protected function mapExtractedDataToFields(array $extractedData, array $fields): array
    {
        $mappedData = [];

        // Direct mapping - if extracted field matches expected field, use it
        foreach ($fields as $fieldName => $fieldConfig) {
            if (isset($extractedData[$fieldName])) {
                $mappedData[$fieldName] = $extractedData[$fieldName];
            }
        }

        // Smart mapping - try to match extracted data to expected fields
        // Common patterns: customer/customer_id, items/products, etc.
        foreach ($extractedData as $extractedKey => $extractedValue) {
            // Skip if already mapped
            if (isset($mappedData[$extractedKey])) {
                continue;
            }

            // Try to find matching field
            foreach ($fields as $fieldName => $fieldConfig) {
                // Skip if already has value
                if (isset($mappedData[$fieldName])) {
                    continue;
                }

                // Dynamic pattern matching - no hardcoded field names
                // Match if extracted key contains field name or vice versa
                // e.g., 'customer_id' matches 'customer', 'items' matches 'item'
                $fieldNameBase = rtrim($fieldName, '_id');
                $extractedKeyBase = rtrim($extractedKey, '_id');
                
                $isMatch = (
                    $fieldNameBase === $extractedKeyBase ||
                    str_contains($fieldName, $extractedKeyBase) ||
                    str_contains($extractedKey, $fieldNameBase) ||
                    // Singular/plural matching
                    rtrim($fieldNameBase, 's') === rtrim($extractedKeyBase, 's')
                );

                // Check if extracted key matches this field dynamically
                if ($isMatch) {
                    // Check if this field should be an array (items, products, etc.)
                    $fieldConfig = $fields[$fieldName] ?? [];
                    $fieldType = is_array($fieldConfig) ? ($fieldConfig['type'] ?? 'string') : 'string';

                    // If field is array type and value is string, convert to array
                    if ($fieldType === 'array' && is_string($extractedValue)) {
                        $configArray = is_array($fieldConfig) ? $fieldConfig : [];
                        $mappedData[$fieldName] = \LaravelAIEngine\Services\AI\FieldDetector::stringToItemArray($extractedValue, $configArray);
                    } else {
                        $mappedData[$fieldName] = $extractedValue;
                    }
                    break;
                }
            }
        }

        return $mappedData;
    }

    /**
     * Generate entity resolution steps automatically
     */
    protected function generateEntityResolutionSteps(array $entities, bool $hasConfirmation = false): array
    {
        $steps = [];
        $entityNames = array_keys($entities);

        foreach ($entityNames as $index => $entityName) {
            $entityConfig = $entities[$entityName];
            $nextEntity = $entityNames[$index + 1] ?? null;

            // Determine next step after this entity
            $nextStep = $nextEntity ? "resolve_{$nextEntity}" :
                        ($hasConfirmation ? 'confirm_action' : 'execute_final_action');

            // Resolve entity step
            // With step prefixing, entity resolution handles subworkflows directly
            // No need for separate handle_missing step - it's all integrated
            $steps[] = WorkflowStep::make("resolve_{$entityName}")
                ->description("Resolve {$entityName}")
                ->execute(fn($ctx) => $this->autoResolveEntity($ctx, $entityName, $entityConfig))
                ->onSuccess($nextStep)
                ->onFailure('error'); // Fail directly if entity can't be resolved
        }

        return $steps;
    }

    /**
     * Auto-resolve entity (search, validate, handle ambiguity)
     * Automatically tracks entity state using EntityState enum
     */
    protected function autoResolveEntity(
        UnifiedActionContext $context,
        string $entityName,
        array $entityConfig
    ): ActionResult {
        // CRITICAL FIX: First check if entity already exists in context
        // This prevents infinite loops when subflow completes but we're still on resolve step
        // Check both entity_id and entity_name_id formats
        $entityId = $context->get($entityName . '_id') ?? $context->get($entityName);

        // For category, also check if it's stored in collected_data
        if (!$entityId) {
            $collectedData = $context->get('collected_data', []);
            // Check field name first (full data object), then _id suffix (backward compatibility)
            if (isset($collectedData[$entityName])) {
                $entityId = is_array($collectedData[$entityName]) ? ($collectedData[$entityName]['id'] ?? null) : $collectedData[$entityName];
            } else {
                $entityId = $collectedData[$entityName . '_id'] ?? null;
            }
        }

        // IMPORTANT: For multiple entities (like products), a single numeric ID means one product was created
        // but there might be more to create. Don't treat it as "all resolved" - let GenericEntityResolver handle it.
        $isMultiple = $entityConfig['multiple'] ?? false;

        // If we have a numeric ID and it's a SINGLE entity (not multiple), entity is resolved
        if ($entityId && is_numeric($entityId) && !$isMultiple) {
            Log::channel('ai-engine')->info('Entity already resolved in context', [
                'entity' => $entityName,
                'entity_id' => $entityId,
            ]);

            // Only clear active_subflow if it's for this specific entity
            // Don't clear if we're in a parent subflow (nested subflows)
            $activeSubflow = $context->get('active_subflow');
            if ($activeSubflow && ($activeSubflow['field_name'] ?? null) === $entityName) {
                $context->forget('active_subflow');
                Log::channel('ai-engine')->info('Cleared active_subflow for resolved entity', [
                    'entity' => $entityName,
                ]);
            }

            return ActionResult::success(
                message: ucfirst($entityName) . " resolved",
                data: [$entityName . '_id' => $entityId, $entityName => $entityId]
            );
        }

        // If we have a string identifier, check if entity was just created in database
        if ($entityId && !is_numeric($entityId)) {
            $modelClass = $entityConfig['model'] ?? null;
            if ($modelClass) {
                try {
                    $searchFields = $entityConfig['search_fields'] ?? ['name'];
                    $query = $modelClass::query();

                    // Apply filters if configured
                    if (!empty($entityConfig['filters']) && is_callable($entityConfig['filters'])) {
                        $query = $entityConfig['filters']($query);
                    }

                    // Search for exact match
                    $query->where(function($q) use ($searchFields, $entityId) {
                        foreach ($searchFields as $field) {
                            $q->orWhere($field, $entityId);
                        }
                    });

                    $entity = $query->first();

                    if ($entity && $entity->id) {
                        Log::channel('ai-engine')->info('Found entity in database, storing numeric ID', [
                            'entity' => $entityName,
                            'entity_id' => $entity->id,
                            'identifier' => $entityId,
                        ]);

                        // Store entity data in collected_data
                        $collectedData = $context->get('collected_data', []);

                        $includeFields = $entityConfig['include_fields'] ?? [];
                        if (!empty($includeFields)) {
                            // Store full entity data under field name from config
                            $entityData = ['id' => $entity->id];
                            foreach ($includeFields as $field) {
                                if (isset($entity->$field)) {
                                    $entityData[$field] = $entity->$field;
                                }
                            }
                            $collectedData[$entityName] = $entityData;

                            Log::channel('ai-engine')->info('Stored full entity data with includeFields', [
                                'entity' => $entityName,
                                'entity_id' => $entity->id,
                                'include_fields' => $includeFields,
                                'stored_data' => $entityData,
                            ]);
                        } else {
                            // Store just the ID if no includeFields specified
                            $collectedData[$entityName] = $entity->id;
                        }

                        // Store numeric ID in context for internal use
                        $context->set($entityName . '_id', $entity->id);
                        $context->set('collected_data', $collectedData);

                        // Clear any active subworkflow state
                        $context->forget('active_subflow');

                        return ActionResult::success(
                            message: ucfirst($entityName) . " resolved",
                            data: [$entityName . '_id' => $entity->id, $entityName => $entity->id]
                        );
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->error('Failed to search for entity', [
                        'entity' => $entityName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Check if there's an active subworkflow for single entity (not multiple)
        // For multiple entities, GenericEntityResolver handles subflow completion
        $isMultiple = $entityConfig['multiple'] ?? false;
        $activeSubflow = $context->get('active_subflow');
        if ($activeSubflow && $activeSubflow['field_name'] === $entityName && !$isMultiple) {
            $stepPrefix = $activeSubflow['step_prefix'] ?? '';
            $currentStep = $context->currentStep ?? '';

            // Check if we're back on the parent resolve step (indicates subflow completed)
            $parentResolveStep = "resolve_{$entityName}";
            $isOnParentResolveStep = str_ends_with($currentStep, $parentResolveStep);

            Log::channel('ai-engine')->info('Active subworkflow detected', [
                'entity' => $entityName,
                'step_prefix' => $stepPrefix,
                'current_step' => $currentStep,
                'parent_resolve_step' => $parentResolveStep,
                'is_on_parent_resolve' => $isOnParentResolveStep,
            ]);

            // If we're back on the parent resolve step, subflow completed
            if ($isOnParentResolveStep) {
                Log::channel('ai-engine')->info('Subworkflow completed, extracting entity', [
                    'entity' => $entityName,
                    'current_step' => $currentStep,
                ]);

                // Clear subworkflow state
                $context->forget('active_subflow');

                // Try to find the created entity by searching for it
                $modelClass = $entityConfig['model'] ?? null;
                $collectedData = $context->get('collected_data', []);
                // Check field name first, then _id suffix
                $identifier = null;
                if (isset($collectedData[$entityName])) {
                    $identifier = is_array($collectedData[$entityName]) ? ($collectedData[$entityName]['name'] ?? null) : $collectedData[$entityName];
                }
                if (!$identifier) {
                    $identifier = $collectedData[$entityName . '_id'] ?? $context->get($entityName . '_identifier');
                }

                if ($modelClass && $identifier && !is_numeric($identifier)) {
                    try {
                        // Search for the entity that was just created
                        $searchFields = $entityConfig['search_fields'] ?? ['name'];
                        $query = $modelClass::query();

                        // Apply filters if configured
                        if (!empty($entityConfig['filters']) && is_callable($entityConfig['filters'])) {
                            $query = $entityConfig['filters']($query);
                        }

                        // Search for exact match
                        $query->where(function($q) use ($searchFields, $identifier) {
                            foreach ($searchFields as $field) {
                                $q->orWhere($field, $identifier);
                            }
                        });

                        $entity = $query->first();

                        if ($entity && $entity->id) {
                            Log::channel('ai-engine')->info('Found created entity, storing ID', [
                                'entity' => $entityName,
                                'entity_id' => $entity->id,
                                'identifier' => $identifier,
                            ]);

                            // Store entity data in collected_data
                            $collectedData = $context->get('collected_data', []);

                            $includeFields = $entityConfig['include_fields'] ?? [];
                            if (!empty($includeFields)) {
                                // Store full entity data under field name from config
                                $entityData = ['id' => $entity->id];
                                foreach ($includeFields as $field) {
                                    if (isset($entity->$field)) {
                                        $entityData[$field] = $entity->$field;
                                    }
                                }
                                $collectedData[$entityName] = $entityData;

                                Log::channel('ai-engine')->info('Stored full entity data with includeFields (after creation)', [
                                    'entity' => $entityName,
                                    'entity_id' => $entity->id,
                                    'include_fields' => $includeFields,
                                    'stored_data' => $entityData,
                                ]);
                            } else {
                                // Store just the ID if no includeFields specified
                                $collectedData[$entityName] = $entity->id;
                            }

                            // Store numeric ID in context for internal use
                            $context->set($entityName . '_id', $entity->id);
                            $context->set('collected_data', $collectedData);

                            return ActionResult::success(
                                message: ucfirst($entityName) . " created successfully",
                                data: [$entityName . '_id' => $entity->id, $entityName => $entity->id]
                            );
                        }
                    } catch (\Exception $e) {
                        Log::channel('ai-engine')->error('Failed to find created entity', [
                            'entity' => $entityName,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Fall through to normal resolution - entity_id check above will catch it next time
            }
        }

        $identifierField = $entityConfig['identifier_field'] ?? $entityName;
        $modelClass = $entityConfig['model'] ?? null;
        $isMultiple = $entityConfig['multiple'] ?? false;

        Log::channel('ai-engine')->info('🔍 AutomatesSteps::autoResolveEntity called', [
            'entity' => $entityName,
            'identifier_field' => $identifierField,
            'model' => $modelClass,
            'has_subflow' => !empty($entityConfig['subflow']),
            'subflow' => $entityConfig['subflow'] ?? null,
        ]);

        if (!$modelClass) {
            $context->setEntityState($entityName, EntityState::FAILED, "Model class not configured");
            return ActionResult::failure(error: "Model class not configured for {$entityName}");
        }

        // Get identifier from collected data
        $collectedData = $context->get('collected_data', []);

        // IMPORTANT: For multiple entities, restore parent collected_data if needed
        // This ensures the items array is available after a subflow completes
        if ($isMultiple && !isset($collectedData[$identifierField])) {
            $parentCollectedData = $context->get('parent_collected_data');
            if ($parentCollectedData && isset($parentCollectedData[$identifierField])) {
                $context->set('collected_data', $parentCollectedData);
                $collectedData = $parentCollectedData;

                Log::channel('ai-engine')->info('AutomatesSteps: Restored parent collected_data', [
                    'entity' => $entityName,
                    'identifier_field' => $identifierField,
                    'has_identifier' => isset($collectedData[$identifierField]),
                ]);
            }
        }

        $identifier = $collectedData[$identifierField] ?? null;

        // Apply custom parser if identifier is a string and field expects array
        if ($identifier && is_string($identifier) && ($entityConfig['multiple'] ?? false)) {
            if (isset($entityConfig['custom_parser']) && $entityConfig['custom_parser'] instanceof \Closure) {
                try {
                    $parsed = $entityConfig['custom_parser']($identifier);
                    if (is_array($parsed) && !empty($parsed)) {
                        Log::channel('ai-engine')->info('AutomatesSteps: Custom parser converted string to array', [
                            'entity' => $entityName,
                            'original' => $identifier,
                            'parsed_count' => count($parsed),
                        ]);
                        $identifier = $parsed;
                        // Update collected_data with parsed array
                        $collectedData[$identifierField] = $parsed;
                        $context->set('collected_data', $collectedData);
                    }
                } catch (\Exception $e) {
                    Log::channel('ai-engine')->warning('AutomatesSteps: Custom parser failed', [
                        'entity' => $entityName,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                // No custom parser but field expects array - convert string to array automatically
                Log::channel('ai-engine')->info('AutomatesSteps: Auto-converting string to array for multiple entity', [
                    'entity' => $entityName,
                    'identifier' => $identifier,
                ]);
                $identifier = \LaravelAIEngine\Services\AI\FieldDetector::stringToItemArray($identifier, $entityConfig);
                $collectedData[$identifierField] = $identifier;
                $context->set('collected_data', $collectedData);
            }
        }

        Log::channel('ai-engine')->info('AutomatesSteps: Looking for identifier in collected_data', [
            'entity' => $entityName,
            'identifier_field' => $identifierField,
            'collected_data_keys' => array_keys($collectedData),
            'collected_data' => $collectedData,
            'identifier_found' => $identifier,
        ]);

        // Check for identifier provider in entity config
        if (!$identifier && isset($entityConfig['identifier_provider']) && $entityConfig['identifier_provider'] instanceof \Closure) {
            $identifier = $entityConfig['identifier_provider']($context);
            if ($identifier) {
                // Update collected data with generated identifier
                $collectedData[$identifierField] = $identifier;
                $context->set('collected_data', $collectedData);

                \Illuminate\Support\Facades\Log::info('Generated identifier from provider', [
                    'entity' => $entityName,
                    'identifier' => $identifier,
                ]);
            }
        }

        // Check for custom resolution methods first (even if identifier is empty)
        // Custom resolvers can generate identifiers (e.g., AI-powered suggestions)
        if ($isMultiple) {
            $customMethod = "resolveEntities_{$entityName}";
            if (method_exists($this, $customMethod)) {
                $items = is_array($identifier) ? $identifier : ($identifier ? [$identifier] : []);
                return $this->$customMethod($context, $items, $entityConfig);
            }
        } else {
            $customMethod = "resolveEntity_{$entityName}";
            if (method_exists($this, $customMethod)) {
                return $this->$customMethod($context, $identifier, $entityConfig);
            }
        }

        // If no custom resolver and no identifier, fail
        if (!$identifier) {
            $context->setEntityState($entityName, EntityState::FAILED, "Identifier not found");
            return ActionResult::failure(error: "{$entityName} identifier not found");
        }

        // Mark as pending while resolving
        $context->setEntityState($entityName, EntityState::PENDING, $identifier);

        // Use GenericEntityResolver if available
        if (class_exists(\LaravelAIEngine\Services\GenericEntityResolver::class)) {
            $resolver = app(\LaravelAIEngine\Services\GenericEntityResolver::class);

            $result = null;
            if ($isMultiple) {
                $items = is_array($identifier) ? $identifier : [$identifier];
                $result = $resolver->resolveEntities($entityName, $entityConfig, $items, $context);
            } else {
                $result = $resolver->resolveEntity($entityName, $entityConfig, $identifier, $context);
            }

            // Track state based on result
            if ($result->success) {
                $entityId = $result->data[$entityName . '_id'] ?? $result->data[$entityName] ?? null;
                $context->setEntityState($entityName, EntityState::RESOLVED, $entityId);

                // Store the entity ID in context with the correct key for the workflow to use
                // The workflow expects 'category_id' not just 'category'
                if ($entityId) {
                    $context->set($entityName . '_id', $entityId);

                    // IMPORTANT: Also update collected_data so the final action can access it
                    // Store under field name if includeFields is specified, otherwise use _id suffix
                    $collectedData = $context->get('collected_data', []);
                    $includeFields = $entityConfig['include_fields'] ?? [];

                    if (!empty($includeFields)) {
                        // Fetch and store full entity data
                        $modelClass = $entityConfig['model'] ?? null;
                        if ($modelClass && class_exists($modelClass)) {
                            try {
                                $entity = $modelClass::find($entityId);
                                if ($entity) {
                                    $entityData = ['id' => $entity->id];
                                    foreach ($includeFields as $field) {
                                        if (isset($entity->$field)) {
                                            $entityData[$field] = $entity->$field;
                                        }
                                    }
                                    
                                    // IMPORTANT: Preserve user modifications - don't overwrite existing values
                                    // User modifications take precedence over database values
                                    $existingData = $collectedData[$entityName] ?? [];
                                    if (is_array($existingData)) {
                                        // Merge: database values first, then existing (user-modified) values on top
                                        $collectedData[$entityName] = array_merge($entityData, $existingData);
                                    } else {
                                        $collectedData[$entityName] = $entityData;
                                    }

                                    Log::channel('ai-engine')->info('Stored full entity data after resolution', [
                                        'entity' => $entityName,
                                        'entity_id' => $entityId,
                                        'include_fields' => $includeFields,
                                        'stored_data' => $collectedData[$entityName],
                                        'preserved_user_modifications' => !empty($existingData),
                                    ]);
                                }
                            } catch (\Exception $e) {
                                Log::channel('ai-engine')->warning('Failed to fetch full entity data after resolution', [
                                    'entity' => $entityName,
                                    'entity_id' => $entityId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }

                    // Store resolved entity data
                    // For array entities (like products), merge with identifier field data (e.g., 'items')
                    // which may have richer data from subflows (like prices)
                    $identifierField = $entityConfig['identifier_field'] ?? $entityName;
                    
                    if (is_array($entityId)) {
                        // Check if identifier field (e.g., 'items') has richer data with prices
                        $identifierData = $collectedData[$identifierField] ?? [];
                        
                        if (is_array($identifierData) && !empty($identifierData)) {
                            // Merge: identifier field data (with prices from subflow) takes precedence
                            foreach ($entityId as $index => $resolvedItem) {
                                if (isset($identifierData[$index]) && is_array($identifierData[$index])) {
                                    // Identifier data (from subflow) takes precedence over resolved data
                                    $entityId[$index] = array_merge($resolvedItem, $identifierData[$index]);
                                }
                            }
                        }
                        
                        // Store only under entity name (single source of truth)
                        $collectedData[$entityName] = $entityId;
                        
                        // Remove identifier field to avoid duplication
                        if ($identifierField !== $entityName && isset($collectedData[$identifierField])) {
                            unset($collectedData[$identifierField]);
                        }
                    } else {
                        // For single entities, store ID in entity_id field for backward compatibility
                        $collectedData[$entityName . '_id'] = $entityId;
                    }
                    $context->set('collected_data', $collectedData);

                    Log::channel('ai-engine')->info('Entity resolved and stored in context', [
                        'entity' => $entityName,
                        'entity_data' => $entityId,
                        'context_key' => is_array($entityId) ? $entityName : $entityName . '_id',
                        'updated_collected_data' => true,
                    ]);
                }
            } else {
                $context->setEntityState($entityName, EntityState::MISSING, $identifier);
            }

            return $result;
        }

        // Fallback: simple search with EntityState tracking
        return $this->simpleEntitySearch($modelClass, $identifier, $entityName, $entityConfig, $context);
    }

    /**
     * Simple entity search fallback with EntityState tracking
     */
    protected function simpleEntitySearch(
        string $modelClass,
        $identifier,
        string $entityName,
        array $entityConfig,
        UnifiedActionContext $context
    ): ActionResult {
        try {
            $query = $modelClass::query();

            // Use search_fields from config if available
            $searchFields = $entityConfig['search_fields'] ?? ['name', 'title', 'email', 'phone'];

            // Apply filters from config if available
            if (!empty($entityConfig['filters']) && is_callable($entityConfig['filters'])) {
                $query = $entityConfig['filters']($query);
            }

            // Search across configured fields
            $query->where(function($q) use ($searchFields, $identifier) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'LIKE', "%{$identifier}%");
                }
            });

            $results = $query->limit(10)->get();

            if ($results->isEmpty()) {
                $context->setEntityState($entityName, EntityState::MISSING, $identifier);
                return ActionResult::failure(error: "{$entityName} not found");
            }

            if ($results->count() === 1) {
                $entity = $results->first();
                $entityId = $entity->id ?? $entity->{$entityName . '_id'} ?? null;

                $context->setEntityState($entityName, EntityState::RESOLVED, $entityId);
                $context->set($entityName . '_id', $entityId);

                $entityName_display = $entity->name ?? $entityId;
                return ActionResult::success(
                    message: "{$entityName} found: {$entityName_display}",
                    data: [$entityName => $entity]
                );
            }

            // Multiple matches - need user choice
            $context->setEntityState($entityName, EntityState::PENDING, $identifier);
            return ActionResult::needsUserInput(
                message: "Found multiple {$entityName} matches. Which one?",
                data: ['matches' => $results],
                metadata: ['needs_choice' => true, 'entity' => $entityName]
            );

        } catch (\Exception $e) {
            $context->setEntityState($entityName, EntityState::FAILED, $e->getMessage());
            return ActionResult::failure(error: "Search failed: {$e->getMessage()}");
        }
    }

    /**
     * Auto-handle missing entity (create or use subflow)
     */
    protected function autoHandleMissingEntity(
        UnifiedActionContext $context,
        string $entityName,
        array $entityConfig
    ): ActionResult {
        // Check if confirmation is required before creating
        // If so, let GenericEntityResolver handle it (it will ask for confirmation then start subflow)
        $confirmBeforeCreate = $entityConfig['confirm_before_create'] ?? false;

        if ($confirmBeforeCreate) {
            // GenericEntityResolver will handle the confirmation flow and subflow execution
            // This is already being called from autoResolveEntity, so we shouldn't reach here
            // But if we do, return a failure to indicate the entity needs to be resolved
            return ActionResult::failure(
                error: "Entity requires user confirmation before creation"
            );
        }

        // Check if we're in a subflow
        $inSubflow = $context->get('in_subflow');
        $subflowEntity = $context->get('subflow_entity');

        // If we're in a subflow for this entity, continue it
        if ($inSubflow && $subflowEntity === $entityName) {
            $subflowClass = $entityConfig['subflow'] ?? null;
            if ($subflowClass && class_exists($subflowClass)) {
                return $this->executeSubflow($subflowClass, $context, $entityName);
            }
        }

        // Start new subflow if configured (without confirmation)
        $subflowClass = $entityConfig['subflow'] ?? null;
        if ($subflowClass && class_exists($subflowClass)) {
            return $this->executeSubflow($subflowClass, $context, $entityName);
        }

        // Fallback: ask user if they want to create
        return ActionResult::needsUserInput(
            message: "{$entityName} not found. Would you like to create it?",
            metadata: ['awaiting_confirmation' => true, 'entity' => $entityName]
        );
    }

    /**
     * Execute subflow for entity creation using workflow stack
     *
     * This method manages the subworkflow lifecycle:
     * 1. First call: Push parent onto stack, start subworkflow, return needs_input
     * 2. Subsequent calls: Continue subworkflow until complete
     * 3. On complete: Pop parent from stack, extract results, return success
     */
    protected function executeSubflow(
        string $subflowClass,
        UnifiedActionContext $context,
        string $entityName
    ): ActionResult {
        try {
            $agentMode = app(\LaravelAIEngine\Services\Agent\AgentMode::class);

            // Check if we're already in this subflow
            if ($context->isInSubworkflow()) {
                // We're in a subflow - continue it
                $lastMessage = $context->conversationHistory[count($context->conversationHistory) - 1]['content'] ?? '';

                \Illuminate\Support\Facades\Log::info("Continuing subworkflow", [
                    'subflow' => class_basename($context->currentWorkflow ?? ''),
                    'entity' => $entityName,
                    'is_isolated' => $context->metadata['is_subworkflow'] ?? false,
                ]);

                $response = $agentMode->continueWorkflow($lastMessage, $context);

                // Check if subflow completed
                if ($response->isComplete && $response->success) {
                    // Subflow completed - merge result back to parent
                    $parent = $context->popWorkflow();

                    \Illuminate\Support\Facades\Log::info("Subworkflow completed, returning to parent", [
                        'parent' => $parent['workflow'] ?? 'none',
                        'entity' => $entityName,
                        'result_keys' => array_keys($response->data ?? []),
                    ]);

                    // Merge only the result, not intermediate state
                    $context->mergeSubworkflowResult($response->data ?? []);

                    // Also set entity-specific fields for backward compatibility
                    // Dynamic: set {entityName}_id if present in response data
                    $entityIdKey = $entityName . '_id';
                    if (isset($response->data[$entityIdKey])) {
                        $context->set($entityIdKey, $response->data[$entityIdKey]);
                    }

                    return ActionResult::success(
                        message: "✅ {$entityName} created successfully",
                        data: $response->data
                    );
                }

                // Subflow still needs input
                if ($response->needsUserInput) {
                    return ActionResult::needsUserInput(
                        message: $response->message,
                        data: $response->data,
                        metadata: ['in_subflow' => true, 'entity' => $entityName]
                    );
                }

                // Subflow failed - propagate the actual error message
                $context->popWorkflow();
                $errorMessage = $response->error ?: $response->message ?: "Subflow failed to create {$entityName}";
                return ActionResult::failure(error: $errorMessage);
            }

            // First time - start new subflow with ISOLATED CONTEXT
            $collectedData = $context->get('collected_data', []);
            $identifierField = $entityName . '_identifier';
            $identifier = $collectedData[$identifierField] ?? $collectedData['customer_identifier'] ?? '';

            // Create isolated subcontext
            $subContext = $context->createSubContext([
                'entity_identifier' => $identifier,
                'entity_name' => $entityName,
            ]);

            // Set the subworkflow class
            $subContext->currentWorkflow = $subflowClass;
            $subContext->currentStep = null;

            // Copy the workflow stack from parent (for isInSubworkflow check)
            $subContext->workflowStack = $context->workflowStack;
            $subContext->workflowStack[] = [
                'workflow' => $context->currentWorkflow,
                'step' => $context->currentStep,
                'state' => $context->workflowState,
            ];

            \Illuminate\Support\Facades\Log::info("Starting subworkflow with isolated context", [
                'parent' => class_basename($context->currentWorkflow ?? 'none'),
                'subflow' => class_basename($subflowClass),
                'entity' => $entityName,
                'isolated' => true,
                'initial_data' => array_keys($subContext->workflowState),
            ]);

            // Start the subworkflow with isolated context
            $response = $agentMode->startWorkflow($subflowClass, $subContext, '');

            // Replace parent's context with the subcontext (which has isolated state)
            // This ensures subsequent calls use the isolated context
            $context->conversationHistory = $subContext->conversationHistory;
            $context->currentWorkflow = $subContext->currentWorkflow;
            $context->currentStep = $subContext->currentStep;
            $context->workflowState = $subContext->workflowState;
            $context->workflowStack = $subContext->workflowStack;
            $context->metadata = $subContext->metadata;

            // Save the updated context
            $context->saveToCache();

            // The subworkflow has started and will need user input
            if ($response->needsUserInput) {
                return ActionResult::needsUserInput(
                    message: $response->message,
                    data: $response->data,
                    metadata: ['in_subflow' => true, 'entity' => $entityName, 'subflow_started' => true]
                );
            }

            // If subworkflow completed immediately (unlikely)
            if ($response->isComplete && $response->success) {
                $context->popWorkflow();
                $context->mergeSubworkflowResult($response->data ?? []);

                // Dynamic: set {entityName}_id if present in response data
                $entityIdKey = $entityName . '_id';
                if (isset($response->data[$entityIdKey])) {
                    $context->set($entityIdKey, $response->data[$entityIdKey]);
                }

                return ActionResult::success(
                    message: "✅ {$entityName} created successfully",
                    data: $response->data
                );
            }

            // Unexpected state
            $context->popWorkflow();
            return ActionResult::failure(error: "Subworkflow started but in unexpected state");

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Subworkflow execution failed", [
                'subflow' => $subflowClass,
                'entity' => $entityName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Clean up stack on error
            if ($context->isInSubworkflow()) {
                $context->popWorkflow();
            }

            return ActionResult::failure(error: "Failed to create {$entityName}: {$e->getMessage()}");
        }
    }

    /**
     * Simple entity creation (override for custom logic)
     */
    protected function createEntity(
        string $entityName,
        array $entityConfig,
        UnifiedActionContext $context
    ): ActionResult {
        // Override this method in your workflow for custom creation logic
        return ActionResult::failure(
            error: "Entity creation not implemented. Use subflow or override createEntity()"
        );
    }

    /**
     * Collect missing fields for items in arrays (e.g., prices for products)
     */
    protected function collectMissingArrayItemFields(UnifiedActionContext $context, array $config): ActionResult
    {
        // Check if we're waiting for a response to update an array item
        $waitingForArrayField = $context->get('current_array_field');

        if ($waitingForArrayField) {
            // User has responded - extract the value from their message
            $conversationHistory = $context->conversationHistory ?? [];
            $lastUserMessage = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');

            if (!empty($lastUserMessage)) {
                $lastMsg = end($lastUserMessage);
                $userResponse = trim($lastMsg['content'] ?? '');

                // Extract numeric value from response
                $value = $this->extractNumericValue($userResponse);

                if ($value !== null) {
                    // Update the array item with the provided value
                    $entityName = $context->get('current_array_entity');
                    $itemIndex = $context->get('current_array_item_index');
                    $fieldName = $context->get('current_array_field');

                    $entityConfig = $config['entities'][$entityName] ?? [];
                    $identifierField = $entityConfig['identifier_field'] ?? $entityName;
                    $arrayData = $context->get($identifierField, []);

                    if (isset($arrayData[$itemIndex])) {
                        $arrayData[$itemIndex][$fieldName] = $value;
                        $context->set($identifierField, $arrayData);

                        // IMPORTANT: Also update collected_data so the value persists
                        $collectedData = $context->get('collected_data', []);
                        if (!isset($collectedData[$identifierField])) {
                            $collectedData[$identifierField] = [];
                        }
                        $collectedData[$identifierField] = $arrayData;
                        $context->set('collected_data', $collectedData);

                        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Updated array item field', [
                            'entity' => $entityName,
                            'item_index' => $itemIndex,
                            'field' => $fieldName,
                            'value' => $value,
                            'updated_collected_data' => true,
                        ]);

                        // Move to next item
                        $context->set('current_array_item_index', $itemIndex + 1);
                        $context->forget('current_array_field');
                    }
                }
            }
        }

        $entities = $config['entities'] ?? [];

        // Check each entity that has array data
        foreach ($entities as $entityName => $entityConfig) {
            $entityIdKey = $entityName . '_id';
            $entityIds = $context->get($entityIdKey);

            // Skip if not an array of IDs (single entity)
            if (!is_array($entityIds)) {
                continue;
            }

            // Get the actual array data (e.g., products array)
            $identifierField = $entityConfig['identifier_field'] ?? $entityName;
            $arrayData = $context->get($identifierField, []);

            if (empty($arrayData) || !is_array($arrayData)) {
                continue;
            }

            // Define required fields for array items (e.g., price for products)
            $requiredItemFields = $entityConfig['required_item_fields'] ?? ['price'];

            // Check each item for missing required fields
            $currentItemIndex = $context->get('current_array_item_index', 0);

            for ($i = $currentItemIndex; $i < count($arrayData); $i++) {
                $item = $arrayData[$i];

                // Check if this item is missing any required fields
                foreach ($requiredItemFields as $fieldName) {
                    if (!isset($item[$fieldName]) || $item[$fieldName] === null || $item[$fieldName] === '') {
                        // Missing field found - ask for it
                        $fieldNames = \LaravelAIEngine\Services\AI\FieldDetector::getFieldNames($entityConfig);
                        $itemName = $item[$fieldNames['identifier']] ?? "item " . ($i + 1);
                        $quantity = $item[$fieldNames['quantity']] ?? 1;

                        // Store current position
                        $context->set('current_array_item_index', $i);
                        $context->set('current_array_field', $fieldName);
                        $context->set('current_array_entity', $entityName);

                        // Make field name friendly (e.g., "sale_price" -> "sale price")
                        $friendlyFieldName = str_replace('_', ' ', $fieldName);

                        $message = "What is the {$friendlyFieldName} for **{$itemName}**?";
                        if ($quantity > 1) {
                            $message .= " (unit {$friendlyFieldName} per item)";
                        }

                        return ActionResult::needsUserInput(
                            message: $message,
                            metadata: [
                                'collecting_array_field' => true,
                                'item_index' => $i,
                                'field_name' => $fieldName,
                                'entity_name' => $entityName,
                            ]
                        );
                    }
                }
            }

            // All items have all required fields - clear tracking
            $context->forget('current_array_item_index');
            $context->forget('current_array_field');
            $context->forget('current_array_entity');
        }

        // All array items have all required fields collected
        // Sync the updated array data to the entity context variable
        foreach ($entities as $entityName => $entityConfig) {
            $identifierField = $entityConfig['identifier_field'] ?? $entityName;
            $arrayData = $context->get($identifierField, []);

            if (!empty($arrayData) && is_array($arrayData)) {
                // Also set it with the entity name (e.g., 'products' not just 'items')
                $context->set($entityName, $arrayData);

                \Illuminate\Support\Facades\Log::info('AutomatesSteps: Synced array data to entity name', [
                    'entity' => $entityName,
                    'identifier_field' => $identifierField,
                    'items_count' => count($arrayData),
                ]);
            }
        }

        return ActionResult::success(message: 'All array item fields collected');
    }

    /**
     * Extract numeric value from user response
     */
    protected function extractNumericValue(string $response): ?float
    {
        // Remove common currency symbols and text
        $cleaned = preg_replace('/[^\d.,\-]/', '', $response);
        $cleaned = str_replace(',', '', $cleaned);

        if (is_numeric($cleaned)) {
            return (float) $cleaned;
        }

        return null;
    }

    /**
     * Execute final action
     */
    protected function executeFinalAction(UnifiedActionContext $context, array $config): ActionResult
    {
        $finalAction = $config['final_action'] ?? null;

        if (!$finalAction || !is_callable($finalAction)) {
            return ActionResult::failure(error: 'Final action not configured');
        }

        try {
            $result = $finalAction($context);

            if ($result instanceof ActionResult) {
                return $result;
            }

            return ActionResult::success(
                message: $config['goal'] ?? 'Task completed',
                data: $result
            );

        } catch (\Exception $e) {
            Log::error('Final action failed', [
                'workflow' => get_class($this),
                'error' => $e->getMessage(),
            ]);

            return ActionResult::failure(error: "Failed: {$e->getMessage()}");
        }
    }

    /**
     * Helper method to retry a field when validation fails
     * Automatically handles clearing the invalid value and transitioning back to data collection
     */
    protected function retryField(UnifiedActionContext $context, string $fieldName, string $message): ActionResult
    {
        // Clear the invalid field value
        $collectedData = $context->get('collected_data', []);
        unset($collectedData[$fieldName]);
        $context->set('collected_data', $collectedData);
        $context->set('asking_for', $fieldName);

        // Get step prefix for subflow handling
        $currentStep = $context->currentStep ?? '';
        $stepPrefix = str_contains($currentStep, '__')
            ? substr($currentStep, 0, strpos($currentStep, '__') + 2)
            : '';

        // Transition back to collect_data step
        $context->currentStep = $stepPrefix . 'collect_data';

        return ActionResult::needsUserInput(
            message: $message,
            metadata: ['retry_field' => $fieldName]
        );
    }

/**
 * Enrich entity data with includeFields
 * Converts entity IDs/strings to full data objects when includeFields is specified
 */
protected function enrichEntitiesWithIncludeFields(array $collectedData, array $entities, $context): array
{
    foreach ($entities as $entityName => $entityConfig) {
        $includeFields = $entityConfig['include_fields'] ?? [];

        // Skip if no includeFields specified
        if (empty($includeFields)) {
            continue;
        }

        $modelClass = $entityConfig['model'] ?? null;
        if (!$modelClass || !class_exists($modelClass)) {
            continue;
        }

        // Check if entity data is just an ID or string (not already enriched)
        $entityValue = $collectedData[$entityName] ?? null;
        $entityId = null;

        // Get entity ID from various sources
        if (is_numeric($entityValue)) {
            $entityId = $entityValue;
        } elseif (is_string($entityValue) && !empty($entityValue)) {
            // It's a string (name) - try to find the entity
            $entityId = $collectedData[$entityName . '_id'] ?? $context->get($entityName . '_id');
        } elseif (is_array($entityValue) && isset($entityValue['id'])) {
            // Already enriched, skip
            continue;
        }

        // Also check _id field
        if (!$entityId) {
            $entityId = $collectedData[$entityName . '_id'] ?? $context->get($entityName . '_id');
        }

        if ($entityId && is_numeric($entityId)) {
            try {
                $entity = $modelClass::find($entityId);

                if ($entity) {
                    $entityData = ['id' => $entity->id];

                    foreach ($includeFields as $field) {
                        if (isset($entity->$field)) {
                            $entityData[$field] = $entity->$field;
                        }
                    }

                    $collectedData[$entityName] = $entityData;

                    Log::channel('ai-engine')->info('Enriched entity with includeFields', [
                        'entity' => $entityName,
                        'entity_id' => $entityId,
                        'include_fields' => $includeFields,
                        'enriched_data' => $entityData,
                    ]);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Failed to enrich entity with includeFields', [
                    'entity' => $entityName,
                    'entity_id' => $entityId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    return $collectedData;
}

/**
 * Normalize collected data types based on field configuration
 * Intelligently converts strings to arrays for entity fields with multiple=true
 */
protected function normalizeCollectedDataTypes(array $collectedData, array $fields): array
{
    foreach ($fields as $fieldName => $fieldConfig) {
        // Skip if field doesn't exist in collected data
        if (!isset($collectedData[$fieldName])) {
            continue;
        }

        // Check if this is an entity field with multiple=true
        if (is_array($fieldConfig)) {
            $fieldType = $fieldConfig['type'] ?? 'string';
            $isMultiple = $fieldConfig['multiple'] ?? false;

            // If it's an entity field expecting multiple items and current value is a string
            if ($fieldType === 'entity' && $isMultiple && is_string($collectedData[$fieldName])) {
                $originalValue = $collectedData[$fieldName];
                // Convert string to array format
                $collectedData[$fieldName] = \LaravelAIEngine\Services\AI\FieldDetector::stringToItemArray($originalValue, $fieldConfig);

                \Illuminate\Support\Facades\Log::info('Normalized field type: string to array', [
                    'field' => $fieldName,
                    'original_value' => $originalValue,
                    'normalized_value' => $collectedData[$fieldName],
                ]);
            }
        }
    }

    return $collectedData;
}

/**
 * Get workflow configuration
 * Must be implemented by workflow
 */
abstract protected function config(): array;
}
