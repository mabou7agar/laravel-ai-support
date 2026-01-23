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

        // Pre-generate suggestions for entity fields with identifierProvider
        // Store only the suggestion strings (not Closures) to avoid serialization errors
        if (!empty($config['entities'])) {
            foreach ($config['entities'] as $entityName => $entityConfig) {
                $identifierField = $entityConfig['identifier_field'] ?? $entityName;

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
                }
                $context->set('attempted_ai_extraction', $attemptedExtraction);

                // Merge mapped data with any existing collected data
                $collectedData = array_merge($collectedData, $mappedData);
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

        $prompt .= "Data:\n" . json_encode($formattedData, JSON_PRETTY_PRINT) . "\n\n";
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

            return $response->getContent() ?? $this->fallbackConfirmationMessage($formattedData);

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

        // Fields to skip (internal/duplicate fields)
        $skipFields = ['name', 'email', 'product_name', 'collected_data', 'items'];

        // Field label mapping for better UX
        $labelMap = [
            'customer_id' => 'Customer',
            'sale_price' => 'Price',
            'purchase_price' => 'Cost',
            'quantity' => 'Quantity',
            'product_type' => 'Type',
            'category_id' => 'Category',
            'unit_id' => 'Unit',
        ];

        foreach ($data as $key => $value) {
            // Skip internal/duplicate fields
            if (in_array($key, $skipFields)) {
                continue;
            }

            // Get user-friendly label
            $label = $labelMap[$key] ?? ucfirst(str_replace('_', ' ', $key));

            if (is_array($value)) {
                // Handle array values (like products list)
                $message .= "**{$label}:**\n";
                $grandTotal = 0;

                foreach ($value as $item) {
                    if (is_array($item)) {
                        // Extract readable product info
                        $productName = $item['name'] ?? $item['product'] ?? $item['item'] ?? 'Unknown';
                        $qty = $item['quantity'] ?? 1;
                        $price = $item['price'] ?? $item['price_each'] ?? null;

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

                // Format prices
                if (in_array($key, ['sale_price', 'purchase_price', 'price']) && is_numeric($value)) {
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

        // Get config to access entity parsing guides
        $config = method_exists($this, 'config') ? $this->config() : [];
        $entities = $config['entities'] ?? [];

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

        // Add entity-specific parsing guides if available
        $hasParsingGuides = false;
        foreach ($entities as $entityName => $entityConfig) {
            $identifierField = $entityConfig['identifier_field'] ?? $entityName;
            if (isset($fields[$identifierField]) && !empty($entityConfig['parsing_guide'])) {
                if (!$hasParsingGuides) {
                    $prompt .= "\nEntity-Specific Parsing Rules:\n";
                    $hasParsingGuides = true;
                }
                $prompt .= "\nFor '{$identifierField}' field:\n";
                $prompt .= $entityConfig['parsing_guide'] . "\n";
            }
        }

        $prompt .= "\nGeneral Rules:\n";
        $prompt .= "- Only extract fields that are clearly mentioned in the message\n";
        $prompt .= "- Return empty object {} if no fields can be extracted\n";
        $prompt .= "- For numeric fields, extract only numbers\n";
        $prompt .= "- CRITICAL: For array fields (like 'items'), ALWAYS extract as array of OBJECTS, never as array of strings\n";
        $prompt .= "- Each item in an array MUST be an object with 'product' and 'quantity' fields\n";
        $prompt .= "- IMPORTANT: Preserve COMPLETE names including ALL details (model numbers, versions, specifications, sizes, colors, etc.)\n";
        $prompt .= "- Never truncate or abbreviate names - extract the FULL name exactly as stated\n";
        $prompt .= "- When user says 'X and Y', extract as TWO separate objects in the array\n";
        $prompt .= "- If quantity not specified, use 1 as default\n";
        $prompt .= "- Don't guess or infer data not explicitly stated\n\n";

        $prompt .= "REQUIRED FORMAT for items/products:\n";
        $prompt .= "CORRECT: {\"items\": [{\"product\": \"Macbook Pro M4 Max\", \"quantity\": 1}, {\"product\": \"iPhone 15\", \"quantity\": 1}]}\n";
        $prompt .= "WRONG: {\"items\": [\"Macbook Pro M4 Max\", \"iPhone 15\"]}\n\n";

        $prompt .= "Return ONLY valid JSON with extracted fields following the format above.";

        try {
            // askAI expects array context, not UnifiedActionContext
            $response = $this->askAI($prompt, []);

            // Clean response - remove markdown code blocks if present
            $response = preg_replace('/```json\s*|\s*```/', '', $response);
            $response = trim($response);

            // Parse JSON response
            $extracted = json_decode($response, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($extracted)) {
                // Normalize array items - convert string arrays to object arrays
                $extracted = $this->normalizeExtractedArrays($extracted, $fields);
                
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
                $normalizedItems = [];
                foreach ($value as $item) {
                    $normalizedItems[] = [
                        'product' => $item,
                        'name' => $item,
                        'quantity' => 1,
                    ];
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
            $entityId = $collectedData[$entityName . '_id'] ?? null;
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

                        // Store the numeric ID
                        $context->set($entityName . '_id', $entity->id);
                        $collectedData = $context->get('collected_data', []);
                        $collectedData[$entityName . '_id'] = $entity->id;
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
                $identifier = $collectedData[$entityName . '_id'] ?? $context->get($entityName . '_identifier');

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

                            // Store the numeric ID
                            $context->set($entityName . '_id', $entity->id);
                            $collectedData[$entityName . '_id'] = $entity->id;
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
                    // This ensures createProduct() can read the numeric category_id
                    $collectedData = $context->get('collected_data', []);
                    $collectedData[$entityName . '_id'] = $entityId;
                    $context->set('collected_data', $collectedData);

                    Log::channel('ai-engine')->info('Entity resolved and stored in context', [
                        'entity' => $entityName,
                        'entity_id' => $entityId,
                        'context_key' => $entityName . '_id',
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

                        \Illuminate\Support\Facades\Log::info('AutomatesSteps: Updated array item field', [
                            'entity' => $entityName,
                            'item_index' => $itemIndex,
                            'field' => $fieldName,
                            'value' => $value,
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
                        $itemName = $item['name'] ?? $item['product'] ?? $item['item'] ?? "item " . ($i + 1);
                        $quantity = $item['quantity'] ?? 1;

                        // Store current position
                        $context->set('current_array_item_index', $i);
                        $context->set('current_array_field', $fieldName);
                        $context->set('current_array_entity', $entityName);

                        $message = "Could you let me know the {$fieldName} for the {$itemName}?";
                        if ($quantity > 1) {
                            $message .= " (unit {$fieldName} per item)";
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

        // All array items have all required fields
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
     * Must be implemented by workflow
     */
    abstract protected function config(): array;
}
