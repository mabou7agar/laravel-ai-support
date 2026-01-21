<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent CRUD Handler
 *
 * Detects CRUD operations (Create, Read, Update, Delete) from natural language
 * and handles them intelligently without requiring separate workflows for each operation.
 */
class IntelligentCRUDHandler
{
    public function __construct(
        protected AIEngineService $ai,
        protected \LaravelAIEngine\Services\Agent\AgentCollectionAdapter $agentAdapter,
        protected ?IntelligentPromptGenerator $intelligentPrompt = null
    ) {
        $this->intelligentPrompt = $intelligentPrompt ?? app(IntelligentPromptGenerator::class);
    }

    /**
     * Detect CRUD operation from user message
     *
     * Returns: ['operation' => 'create|read|update|delete', 'entity' => 'product|customer|invoice', 'identifier' => 'id or search term']
     */
    public function detectOperation(string $message, array $conversationHistory = []): ?array
    {
        try {
            // Create context for intelligent prompt generation
            $context = new UnifiedActionContext(
                sessionId: 'crud-detection',
                userId: null
            );
            
            // Add conversation history to context
            if (!empty($conversationHistory)) {
                $context->conversationHistory = $conversationHistory;
            }
            
            // Generate intelligent prompt for CRUD detection (simplified - no AI call)
            $contextualPrompt = $this->intelligentPrompt->generatePrompt(
                $message,
                $context,
                ['operation' => 'crud_detection']
            );
            
            // Discover available entities dynamically
            $availableEntities = $this->getAvailableEntities();
            
            // Build detection prompt
            $prompt = $contextualPrompt . "\n\n";
            $prompt .= "TASK: Detect CRUD operation\n\n";
            
            $prompt .= "OPERATIONS:\n";
            $prompt .= "- CREATE: User wants to create/add/make a new entity\n";
            $prompt .= "- READ: User wants to view/show/list/find/search entities\n";
            $prompt .= "- UPDATE: User wants to edit/modify/change/update an existing entity\n";
            $prompt .= "- DELETE: User wants to remove/delete/cancel an entity\n\n";
            
            $prompt .= "AVAILABLE ENTITIES:\n";
            if (!empty($availableEntities)) {
                foreach ($availableEntities as $entity => $description) {
                    $prompt .= "- {$entity}: {$description}\n";
                }
            } else {
                $prompt .= "- Any entity type mentioned by the user\n";
            }
            $prompt .= "\n";
            
            $prompt .= "EXAMPLES:\n";
            $prompt .= "- 'create invoice' → {\"operation\": \"create\", \"entity\": \"invoice\", \"identifier\": null}\n";
            $prompt .= "- 'update product 123' → {\"operation\": \"update\", \"entity\": \"product\", \"identifier\": \"123\"}\n";
            $prompt .= "- 'delete the simple product' → {\"operation\": \"delete\", \"entity\": \"product\", \"identifier\": \"simple product\"}\n";
            $prompt .= "- 'show all customers' → {\"operation\": \"read\", \"entity\": \"customer\", \"identifier\": null}\n\n";
            
            $prompt .= "Return ONLY valid JSON:\n";
            $prompt .= '{\"operation\": \"create|read|update|delete\", \"entity\": \"product|customer|invoice|etc\", \"identifier\": \"id or search term or null\"}';

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && isset($result['operation'])) {
                Log::channel('ai-engine')->info('CRUD operation detected', $result);
                return $result;
            }

        } catch (\Exception $e) {
            Log::error('CRUD detection failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Handle UPDATE operation
     */
    public function handleUpdate(
        string $entity,
        $identifier,
        UnifiedActionContext $context,
        string $message
    ): ActionResult {
        // Check if we're continuing an existing update operation
        $existingOperation = $context->get('crud_operation');
        $existingEntity = $context->get('crud_entity');

        if ($existingOperation === 'update' && $existingEntity) {
            // Continue existing update flow
            return $this->continueUpdate($context, $message);
        }

        Log::channel('ai-engine')->info('Starting UPDATE operation', [
            'entity' => $entity,
            'identifier' => $identifier,
        ]);

        // Step 1: Check if we need to ask for identifier
        if (!$identifier) {
            $context->set('crud_operation', 'update');
            $context->set('crud_entity', $entity);
            $context->saveToCache();

            return ActionResult::needsUserInput(
                message: "Which {$entity} would you like to update? Please provide the name or ID."
            );
        }

        // Store update context
        $context->set('crud_operation', 'update');
        $context->set('crud_entity', $entity);
        $context->set('crud_identifier', $identifier);

        // Step 2: Find the entity
        $modelClass = $this->getModelClass($entity);
        if (!$modelClass) {
            return ActionResult::failure(error: "Unknown entity type: {$entity}");
        }

        $entityRecord = $this->findEntity($modelClass, $identifier);

        if (!$entityRecord) {
            $context->saveToCache();
            return ActionResult::needsUserInput(
                message: "I couldn't find a {$entity} matching '{$identifier}'. Could you provide more details or check the name/ID?"
            );
        }

        // Store the entity to update
        $context->set('crud_entity_record', $entityRecord->toArray());
        $context->set('crud_entity_id', $entityRecord->id);
        $context->set('crud_model_class', $modelClass);

        // Step 3: Extract what fields to update from the message
        $fieldsToUpdate = $this->extractUpdateFields($message, $entity, $entityRecord);

        if (empty($fieldsToUpdate)) {
            $context->saveToCache();
            return ActionResult::needsUserInput(
                message: "What would you like to update for {$entity} '{$entityRecord->name}'? (e.g., 'change the price to 150', 'update the name to XYZ')"
            );
        }

        // Step 4: Apply the updates
        try {
            foreach ($fieldsToUpdate as $field => $value) {
                $entityRecord->$field = $value;
            }
            $entityRecord->save();

            // Clear CRUD context
            $context->forget('crud_operation');
            $context->forget('crud_entity');
            $context->forget('crud_identifier');
            $context->forget('crud_entity_record');
            $context->forget('crud_entity_id');
            $context->forget('crud_model_class');
            $context->saveToCache();

            return ActionResult::success(
                message: "✅ {$entity} '{$entityRecord->name}' updated successfully!",
                data: ['updated_id' => $entityRecord->id, 'updated_fields' => $fieldsToUpdate]
            );
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: "Failed to update {$entity}: " . $e->getMessage()
            );
        }
    }

    /**
     * Continue an existing update operation
     */
    protected function continueUpdate(UnifiedActionContext $context, string $message): ActionResult
    {
        $entity = $context->get('crud_entity');
        $identifier = $context->get('crud_identifier');
        $entityRecord = $context->get('crud_entity_record');
        $modelClass = $context->get('crud_model_class');

        Log::channel('ai-engine')->info('Continuing UPDATE operation', [
            'entity' => $entity,
            'has_identifier' => !empty($identifier),
            'has_record' => !empty($entityRecord),
        ]);

        // If we don't have identifier yet, use the message as identifier
        if (!$identifier) {
            $context->set('crud_identifier', $message);

            if (!$modelClass) {
                $modelClass = $this->getModelClass($entity);
                $context->set('crud_model_class', $modelClass);
            }

            $entityRecord = $this->findEntity($modelClass, $message);

            if (!$entityRecord) {
                $context->saveToCache();
                return ActionResult::needsUserInput(
                    message: "I couldn't find a {$entity} matching '{$message}'. Could you provide the exact name or ID?"
                );
            }

            $context->set('crud_entity_record', $entityRecord->toArray());
            $context->set('crud_entity_id', $entityRecord->id);
            $context->saveToCache();

            return ActionResult::needsUserInput(
                message: "Found {$entity} '{$entityRecord->name}'. What would you like to update? (e.g., 'change the price to 150')"
            );
        }

        // If we have the record, extract update fields from message
        if ($entityRecord && $modelClass) {
            $entityObj = $modelClass::find($entityRecord['id']);

            if (!$entityObj) {
                return ActionResult::failure(error: "Entity no longer exists");
            }

            $fieldsToUpdate = $this->extractUpdateFields($message, $entity, $entityObj);

            if (empty($fieldsToUpdate)) {
                $context->saveToCache();
                return ActionResult::needsUserInput(
                    message: "I didn't understand what to update. Please specify like 'change the price to 150' or 'update the name to XYZ'"
                );
            }

            // Apply updates
            try {
                foreach ($fieldsToUpdate as $field => $value) {
                    $entityObj->$field = $value;
                }
                $entityObj->save();

                // Clear CRUD context
                $context->forget('crud_operation');
                $context->forget('crud_entity');
                $context->forget('crud_identifier');
                $context->forget('crud_entity_record');
                $context->forget('crud_entity_id');
                $context->forget('crud_model_class');
                $context->saveToCache();

                return ActionResult::success(
                    message: "✅ {$entity} '{$entityObj->name}' updated successfully!",
                    data: ['updated_id' => $entityObj->id, 'updated_fields' => $fieldsToUpdate]
                );
            } catch (\Exception $e) {
                return ActionResult::failure(
                    error: "Failed to update {$entity}: " . $e->getMessage()
                );
            }
        }

        return ActionResult::failure(error: "Update operation in invalid state");
    }

    /**
     * Handle DELETE operation
     */
    public function handleDelete(
        string $entity,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        Log::channel('ai-engine')->info('Handling DELETE operation', [
            'entity' => $entity,
            'identifier' => $identifier,
        ]);

        // Step 1: Find the entity to delete
        if (!$identifier) {
            return ActionResult::needsUserInput(
                message: "Which {$entity} would you like to delete? Please provide the name or ID."
            );
        }

        // Step 2: Find the entity
        $modelClass = $this->getModelClass($entity);
        if (!$modelClass) {
            return ActionResult::failure(error: "Unknown entity type: {$entity}");
        }

        $entityRecord = $this->findEntity($modelClass, $identifier);

        if (!$entityRecord) {
            return ActionResult::needsUserInput(
                message: "I couldn't find a {$entity} matching '{$identifier}'. Could you provide more details?"
            );
        }

        // Step 3: Confirm deletion
        $confirmPending = $context->get('crud_delete_confirm_pending');

        if (!$confirmPending) {
            $context->set('crud_delete_confirm_pending', true);
            $context->set('crud_entity_to_delete', $entityRecord->toArray());
            $context->set('crud_entity_id', $entityRecord->id);

            return ActionResult::needsUserInput(
                message: "⚠️ Are you sure you want to delete {$entity} '{$entityRecord->name}'? This action cannot be undone. (yes/no)"
            );
        }

        // Step 4: Check confirmation
        $conversationHistory = $context->conversationHistory ?? [];
        $lastMessage = '';
        if (!empty($conversationHistory)) {
            $lastUserMessages = array_filter($conversationHistory, fn($msg) => ($msg['role'] ?? '') === 'user');
            if (!empty($lastUserMessages)) {
                $lastMsg = end($lastUserMessages);
                $lastMessage = strtolower(trim($lastMsg['content'] ?? ''));
            }
        }

        if (preg_match('/(yes|confirm|ok|sure|delete)/i', $lastMessage)) {
            // Perform deletion
            try {
                $entityRecord->delete();

                $context->forget('crud_delete_confirm_pending');
                $context->forget('crud_entity_to_delete');
                $context->forget('crud_entity_id');

                return ActionResult::success(
                    message: "✅ {$entity} '{$entityRecord->name}' has been deleted successfully.",
                    data: ['deleted_id' => $entityRecord->id]
                );
            } catch (\Exception $e) {
                return ActionResult::failure(
                    error: "Failed to delete {$entity}: " . $e->getMessage()
                );
            }
        } else {
            // Cancelled
            $context->forget('crud_delete_confirm_pending');
            $context->forget('crud_entity_to_delete');

            return ActionResult::success(
                message: "Deletion cancelled. The {$entity} was not deleted."
            );
        }
    }

    /**
     * Get model class from entity name dynamically
     */
    protected function getModelClass(string $entity): ?string
    {
        // Try to discover from registered workflows
        $workflowRegistry = $this->discoverWorkflowEntities();

        $entityLower = strtolower($entity);
        if (isset($workflowRegistry[$entityLower])) {
            return $workflowRegistry[$entityLower];
        }

        // Fallback: Try common Laravel model locations
        $possibleClasses = [
            // App models
            "App\\Models\\" . ucfirst($entity),
            "App\\Models\\" . str_replace(' ', '', ucwords($entity)),

            // Workdo package models
            "Workdo\\Account\\Entities\\" . ucfirst($entity),
            "Workdo\\Account\\Entities\\" . str_replace(' ', '', ucwords($entity)),

            // Special cases
            "Workdo\\Account\\Entities\\ProductService", // for 'product'
            "Workdo\\Account\\Entities\\Vender", // for 'vendor'
        ];

        foreach ($possibleClasses as $class) {
            if (class_exists($class)) {
                Log::channel('ai-engine')->info('Discovered model class', [
                    'entity' => $entity,
                    'class' => $class,
                ]);
                return $class;
            }
        }

        return null;
    }

    /**
     * Discover entity models from AI config (using AgentCollectionAdapter like RAG)
     */
    protected function discoverWorkflowEntities(): array
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        $registry = [];

        try {
            // Use AgentCollectionAdapter to discover models with AI config
            $models = $this->agentAdapter->discoverForAgent(true); // use cache

            foreach ($models as $model) {
                // Only include models with agent_mode strategy (workflows)
                if (($model['strategy'] ?? '') === 'agent_mode') {
                    $entityName = $model['name']; // e.g., "Product", "Customer"
                    $entityKey = strtolower($entityName);
                    $modelClass = $model['class'];

                    $registry[$entityKey] = $modelClass;

                    Log::channel('ai-engine')->debug('Discovered entity from AI config', [
                        'entity' => $entityKey,
                        'model' => $modelClass,
                        'strategy' => $model['strategy'],
                    ]);
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to discover entities from AI config', [
                'error' => $e->getMessage(),
            ]);
        }

        $cache = $registry;

        Log::channel('ai-engine')->info('Discovered entities from AI config', [
            'count' => count($registry),
            'entities' => array_keys($registry),
        ]);

        return $registry;
    }

    /**
     * Get available entities with descriptions from AI config
     */
    protected function getAvailableEntities(): array
    {
        $entities = [];

        try {
            // Use AgentCollectionAdapter to get models with full metadata
            $models = $this->agentAdapter->discoverForAgent(true);

            foreach ($models as $model) {
                // Only include models with agent_mode strategy (workflows)
                if (($model['strategy'] ?? '') === 'agent_mode') {
                    $entityName = $model['name'];
                    $entityKey = strtolower($entityName);

                    // Use description from AI config (from model's getRAGDescription or initializeAI)
                    $description = $model['description'] ?? ucfirst($entityName) . 's';

                    $entities[$entityKey] = $description;
                }
            }

        } catch (\Exception $e) {
            Log::warning('Failed to get entities from AI config', [
                'error' => $e->getMessage(),
            ]);
        }

        return $entities;
    }

    /**
     * Find entity by identifier
     */
    protected function findEntity(string $modelClass, $identifier)
    {
        // Try by ID first
        if (is_numeric($identifier)) {
            $entity = $modelClass::find($identifier);
            if ($entity) {
                return $entity;
            }
        }

        // Try by name
        $entity = $modelClass::where('name', 'LIKE', "%{$identifier}%")->first();
        if ($entity) {
            return $entity;
        }

        // Try by SKU for products
        if (method_exists($modelClass, 'where')) {
            $entity = $modelClass::where('sku', $identifier)->first();
            if ($entity) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * Extract fields to update from message using AI
     */
    protected function extractUpdateFields(string $message, string $entity, $entityRecord): array
    {
        try {
            $prompt = "Extract what fields the user wants to update from their message.\n\n";
            $prompt .= "USER MESSAGE: \"{$message}\"\n";
            $prompt .= "ENTITY TYPE: {$entity}\n";
            $prompt .= "CURRENT VALUES:\n" . json_encode($entityRecord->toArray(), JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "EXAMPLES:\n";
            $prompt .= "- 'change the price to 150' → {\"price\": 150, \"sale_price\": 150}\n";
            $prompt .= "- 'update the name to XYZ' → {\"name\": \"XYZ\"}\n";
            $prompt .= "- 'set quantity to 50' → {\"quantity\": 50}\n\n";
            $prompt .= "Return JSON object with fields to update, or empty object {} if not clear.";

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $fields = json_decode($response->content, true);
            return $fields ?? [];

        } catch (\Exception $e) {
            Log::error('Extract update fields failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
