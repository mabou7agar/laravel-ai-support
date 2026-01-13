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
        $steps[] = WorkflowStep::make('collect_data')
            ->description('Collect required information')
            ->execute(fn($ctx) => $this->autoCollectData($ctx, $config))
            ->onSuccess($nextStepAfterCollection)
            ->onFailure('collect_data');

        // 2. Entity resolution steps (auto-generated for each entity)
        $confirmBeforeComplete = $config['confirm_before_complete'] ?? false;
        if (!empty($entities)) {
            $entitySteps = $this->generateEntityResolutionSteps($entities, $confirmBeforeComplete);
            $steps = array_merge($steps, $entitySteps);
        }

        // 3. Confirmation step (if enabled)
        if ($confirmBeforeComplete) {
            $steps[] = WorkflowStep::make('confirm_action')
                ->description('Confirm before completing')
                ->execute(fn($ctx) => $this->confirmBeforeComplete($ctx, $config))
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

        // Use CollectsWorkflowData trait if available
        if (method_exists($this, 'collectData')) {
            $result = $this->collectData($context);

            // If data collection is complete (success), we should proceed to next step
            // not complete the workflow. Mark this explicitly.
            if ($result->success && !$result->getMetadata('needs_user_input', false)) {
                // Data collection complete - proceed to entity resolution
                return ActionResult::success(
                    message: 'Data collection complete',
                    data: $result->data
                );
            }

            return $result;
        }

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
                }
                $context->set('attempted_ai_extraction', $attemptedExtraction);

                // Merge mapped data with any existing collected data
                $collectedData = array_merge($collectedData, $mappedData);
                $context->set('collected_data', $collectedData);

                \Illuminate\Support\Facades\Log::info('AI extracted data from message', [
                    'workflow' => get_class($this),
                    'extracted_fields' => array_keys($extractedData),
                    'mapped_fields' => array_keys($mappedData),
                    'collected_fields' => array_keys($collectedData),
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
        } else {
            // On subsequent entries, check if user has confirmed
            if ($context->get('user_confirmed_action')) {
                $context->forget('user_confirmed_action');
                $context->forget('confirmation_message_shown');
                return ActionResult::success(message: 'Confirmed');
            }
        }

        // Get collected data to show summary
        $collectedData = $context->get('collected_data', []);
        $entities = $config['entities'] ?? [];

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
                    // For multiple entities, get the actual data from collected_data
                    $identifierField = $entityConfig['identifier_field'] ?? $entityName;
                    $entityData = $collectedData[$identifierField] ?? [];
                    $dataForDisplay[$entityName] = $entityData;
                } else {
                    // For single entity, get the name
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

        // Create user-friendly confirmation message
        // Note: Using fallback formatting to avoid circular dependencies
        $message = $this->fallbackConfirmationMessage($dataForDisplay);

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
                    $response = strtolower(trim($lastMsg['content'] ?? ''));

                    if (str_contains($response, 'yes') || str_contains($response, 'confirm') || str_contains($response, 'ok')) {
                        $context->set('user_confirmed_action', true);
                        return ActionResult::success(message: 'Confirmed');
                    } elseif (str_contains($response, 'no') || str_contains($response, 'cancel')) {
                        return ActionResult::failure(error: 'Action cancelled by user');
                    }
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
     * Enhance confirmation message using AI to make it user-friendly
     */
    protected function enhanceConfirmationMessage(array $data, array $config): string
    {
        if (empty($data)) {
            return "**Please confirm the following details:**\n\nNo data collected yet.";
        }

        $prompt = "Convert the following data into a user-friendly confirmation message. Format it nicely with clear labels and readable values. For arrays of items, list them with bullet points. Keep it concise and professional.\n\n";
        $prompt .= "Data:\n" . json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Return ONLY the formatted confirmation message text, starting with '**Please confirm the following details:**'";

        try {
            $chatService = app(\LaravelAIEngine\Services\ChatService::class);

            $response = $chatService->processMessage(
                message:    $prompt,
                sessionId:  'confirmation_enhancement_' . uniqid(),
                useMemory:  false,
                useActions: false,
                userId:     null
            );

            return $response->content ?? $this->fallbackConfirmationMessage($data);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('AutomatesSteps: AI enhancement failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
            return $this->fallbackConfirmationMessage($data);
        }
    }

    /**
     * Fallback confirmation message if AI enhancement fails
     */
    protected function fallbackConfirmationMessage(array $data): string
    {
        $message = "**Please confirm the following details:**\n\n";

        foreach ($data as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));

            if (is_array($value)) {
                $message .= "**{$label}:**\n";
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $itemStr = $item['name'] ?? json_encode($item);
                        if (isset($item['quantity'])) {
                            $itemStr .= " (qty: {$item['quantity']})";
                        }
                        $message .= "  • {$itemStr}\n";
                    } else {
                        $message .= "  • {$item}\n";
                    }
                }
            } else {
                $message .= "**{$label}:** {$value}\n";
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

            $jsonResponse = json_decode($response->content, true);

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

        // Build generic extraction prompt similar to WorkflowDataCollector
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

        $prompt .= "\nRules:\n";
        $prompt .= "- Only extract fields that are clearly mentioned in the message\n";
        $prompt .= "- Return empty object {} if no fields can be extracted\n";
        $prompt .= "- For numeric fields, extract only numbers\n";
        $prompt .= "- For arrays, extract as array of objects with relevant properties\n";
        $prompt .= "- Don't guess or infer data not explicitly stated\n\n";

        $prompt .= "Return ONLY valid JSON with extracted fields. Example:\n";
        $prompt .= '{"customer_id": "Sarah Mitchell", "items": [{"name": "Pump", "quantity": 1}]}';

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
                    'workflow' => get_class($this),
                    'extracted_fields' => array_keys($extracted),
                    'extracted_data' => $extracted,
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

                // Check for common patterns
                $patterns = [
                    // customer_id matches: customer, customer_name, customer_id
                    'customer_id' => ['customer', 'customer_name', 'customer_email'],
                    'customer' => ['customer_id', 'customer_name', 'customer_email'],
                    // items matches: products, items, product
                    'items' => ['products', 'product', 'items'],
                    'products' => ['items', 'product'],
                    // vendor_id matches: vendor, vendor_name
                    'vendor_id' => ['vendor', 'vendor_name'],
                    'vendor' => ['vendor_id', 'vendor_name'],
                ];

                // Check if extracted key matches any pattern for this field
                if (isset($patterns[$fieldName]) && in_array($extractedKey, $patterns[$fieldName])) {
                    $mappedData[$fieldName] = $extractedValue;
                    break;
                }

                // Check reverse pattern
                if (isset($patterns[$extractedKey]) && in_array($fieldName, $patterns[$extractedKey])) {
                    $mappedData[$fieldName] = $extractedValue;
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
            $steps[] = WorkflowStep::make("resolve_{$entityName}")
                ->description("Resolve {$entityName}")
                ->execute(fn($ctx) => $this->autoResolveEntity($ctx, $entityName, $entityConfig))
                ->onSuccess($nextStep)
                ->onFailure("handle_{$entityName}_missing");

            // Handle missing entity step
            if ($entityConfig['create_if_missing'] ?? false) {
                $steps[] = WorkflowStep::make("handle_{$entityName}_missing")
                    ->description("Handle missing {$entityName}")
                    ->execute(fn($ctx) => $this->autoHandleMissingEntity($ctx, $entityName, $entityConfig))
                    ->onSuccess($nextStep)
                    ->onFailure('error');
            }
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
        $identifierField = $entityConfig['identifier_field'] ?? $entityName;
        $modelClass = $entityConfig['model'] ?? null;
        $isMultiple = $entityConfig['multiple'] ?? false;

        Log::channel('ai-engine')->info('AutomatesSteps::autoResolveEntity called', [
            'entity' => $entityName,
            'identifier_field' => $identifierField,
            'model' => $modelClass,
        ]);

        if (!$modelClass) {
            $context->setEntityState($entityName, EntityState::FAILED, "Model class not configured");
            return ActionResult::failure(error: "Model class not configured for {$entityName}");
        }

        // Get identifier from collected data
        $collectedData = $context->get('collected_data', []);
        $identifier = $collectedData[$identifierField] ?? null;

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

                    Log::channel('ai-engine')->info('Entity resolved and stored in context', [
                        'entity' => $entityName,
                        'entity_id' => $entityId,
                        'context_key' => $entityName . '_id',
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
                    'subflow' => $context->currentWorkflow,
                    'entity' => $entityName,
                    'message' => substr($lastMessage, 0, 50),
                ]);

                $response = $agentMode->continueWorkflow($lastMessage, $context);

                // Check if subflow completed
                if ($response->isComplete && $response->success) {
                    // Subflow completed - pop back to parent
                    $parent = $context->popWorkflow();

                    \Illuminate\Support\Facades\Log::info("Subworkflow completed, returning to parent", [
                        'parent' => $parent['workflow'] ?? 'none',
                        'entity' => $entityName,
                    ]);

                    // Extract created entity ID from subflow response
                    if ($entityName === 'customer' && isset($response->data['customer_id'])) {
                        $context->set('customer_id', $response->data['customer_id']);
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

                // Subflow failed
                $context->popWorkflow();
                return ActionResult::failure(error: "Subflow failed to create {$entityName}");
            }

            // First time - start new subflow
            $collectedData = $context->get('collected_data', []);
            $identifierField = $entityName . '_identifier';
            $identifier = $collectedData[$identifierField] ?? $collectedData['customer_identifier'] ?? '';

            // Prepare state for subworkflow
            $subflowState = [];
            if ($entityName === 'customer' && !empty($identifier)) {
                $subflowState['customer_name'] = $identifier;
            }
            $subflowState['subflow_entity'] = $entityName;

            // Push parent workflow onto stack
            // Note: pushWorkflow sets currentWorkflow and currentStep
            $context->pushWorkflow($subflowClass, null, $subflowState);

            // Clear the current step so startWorkflow begins from the first step
            $context->currentStep = null;

            \Illuminate\Support\Facades\Log::info("Starting subworkflow", [
                'parent' => $context->getParentWorkflow()['workflow'] ?? 'none',
                'subflow' => $subflowClass,
                'entity' => $entityName,
                'identifier' => $identifier,
            ]);

            // Start the subworkflow - this will execute its first step
            $response = $agentMode->startWorkflow($subflowClass, $context, '');

            // The subworkflow has started and will need user input
            // Return this so the parent workflow knows to wait
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

                if ($entityName === 'customer' && isset($response->data['customer_id'])) {
                    $context->set('customer_id', $response->data['customer_id']);
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
     * Must be implemented by workflow
     */
    abstract protected function config(): array;
}
