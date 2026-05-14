<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent CRUD Handler
 *
 * Detects CRUD operations (Create, Read, Update, Delete) from natural language
 * and handles them intelligently without requiring separate handlers for each operation.
 */
class IntelligentCRUDHandler
{
    public function __construct(
        protected AIEngineService $ai,
        protected \LaravelAIEngine\Services\Agent\AgentCollectionAdapter $agentAdapter,
        protected ?IntelligentPromptGenerator $intelligentPrompt = null,
        protected ?LocaleResourceService $localeResources = null
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
            $availableEntitiesLines = '';
            if (!empty($availableEntities)) {
                foreach ($availableEntities as $entity => $description) {
                    $availableEntitiesLines .= "- {$entity}: {$description}\n";
                }
            } else {
                $availableEntitiesLines = $this->t(
                    'ai-engine::runtime.intelligent_crud.prompts.detect_operation.default_entities_line'
                );
            }

            $prompt = $this->locale()->renderPromptTemplate(
                'intelligent_crud/detect_operation',
                [
                    'contextual_prompt' => $contextualPrompt,
                    'available_entities_lines' => trim($availableEntitiesLines),
                ],
                $this->localeCode()
            );
            if ($prompt === '') {
                $prompt = $this->t(
                    'ai-engine::runtime.intelligent_crud.prompts.detect_operation.fallback',
                    '',
                    [
                        'contextual_prompt' => $contextualPrompt,
                        'available_entities_lines' => trim($availableEntitiesLines),
                    ]
                );
            }

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->getContent(), true);

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
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.update.which_entity',
                    "Which {$entity} would you like to update? Please provide the name or ID.",
                    ['entity' => $entity]
                )
            );
        }

        // Store update context
        $context->set('crud_operation', 'update');
        $context->set('crud_entity', $entity);
        $context->set('crud_identifier', $identifier);

        // Step 2: Find the entity
        $modelClass = $this->getModelClass($entity);
        if (!$modelClass) {
            return ActionResult::failure(error: $this->t(
                'ai-engine::runtime.intelligent_crud.unknown_entity_type',
                "Unknown entity type: {$entity}",
                ['entity' => $entity]
            ));
        }

        $entityRecord = $this->findEntity($modelClass, $identifier);

        if (!$entityRecord) {
            $context->saveToCache();
            return ActionResult::needsUserInput(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.entity_not_found',
                    "I couldn't find a {$entity} matching '{$identifier}'. Could you provide more details or check the name/ID?",
                    ['entity' => $entity, 'identifier' => (string) $identifier]
                )
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
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.update.ask_changes',
                    "What would you like to update for {$entity} '{$entityRecord->name}'? (e.g., 'change the price to 150', 'update the name to XYZ')",
                    ['entity' => $entity, 'name' => (string) ($entityRecord->name ?? '')]
                )
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
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.update.success',
                    "✅ {$entity} '{$entityRecord->name}' updated successfully!",
                    ['entity' => $entity, 'name' => (string) ($entityRecord->name ?? '')]
                ),
                data: ['updated_id' => $entityRecord->id, 'updated_fields' => $fieldsToUpdate]
            );
        } catch (\Exception $e) {
            return ActionResult::failure(
                error: $this->t(
                    'ai-engine::runtime.intelligent_crud.update.failed',
                    "Failed to update {$entity}: " . $e->getMessage(),
                    ['entity' => $entity, 'error' => $e->getMessage()]
                )
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
                    message: $this->t(
                        'ai-engine::runtime.intelligent_crud.entity_not_found_exact',
                        "I couldn't find a {$entity} matching '{$message}'. Could you provide the exact name or ID?",
                        ['entity' => $entity, 'identifier' => $message]
                    )
                );
            }

            $context->set('crud_entity_record', $entityRecord->toArray());
            $context->set('crud_entity_id', $entityRecord->id);
            $context->saveToCache();

            return ActionResult::needsUserInput(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.update.found_prompt',
                    "Found {$entity} '{$entityRecord->name}'. What would you like to update? (e.g., 'change the price to 150')",
                    ['entity' => $entity, 'name' => (string) ($entityRecord->name ?? '')]
                )
            );
        }

        // If we have the record, extract update fields from message
        if ($entityRecord && $modelClass) {
            $entityObj = $modelClass::find($entityRecord['id']);

            if (!$entityObj) {
                return ActionResult::failure(error: $this->t(
                    'ai-engine::runtime.intelligent_crud.entity_no_longer_exists',
                    'Entity no longer exists'
                ));
            }

            $fieldsToUpdate = $this->extractUpdateFields($message, $entity, $entityObj);

            if (empty($fieldsToUpdate)) {
                $context->saveToCache();
                return ActionResult::needsUserInput(
                    message: $this->t(
                        'ai-engine::runtime.intelligent_crud.update.not_understood',
                        "I didn't understand what to update. Please specify like 'change the price to 150' or 'update the name to XYZ'"
                    )
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
                    message: $this->t(
                        'ai-engine::runtime.intelligent_crud.update.success',
                        "✅ {$entity} '{$entityObj->name}' updated successfully!",
                        ['entity' => $entity, 'name' => (string) ($entityObj->name ?? '')]
                    ),
                    data: ['updated_id' => $entityObj->id, 'updated_fields' => $fieldsToUpdate]
                );
            } catch (\Exception $e) {
                return ActionResult::failure(
                    error: $this->t(
                        'ai-engine::runtime.intelligent_crud.update.failed',
                        "Failed to update {$entity}: " . $e->getMessage(),
                        ['entity' => $entity, 'error' => $e->getMessage()]
                    )
                );
            }
        }

        return ActionResult::failure(error: $this->t(
            'ai-engine::runtime.intelligent_crud.update.invalid_state',
            'Update operation in invalid state'
        ));
    }

    /**
     * Handle DELETE operation
     */
    public function handleDelete(
        string $entity,
        $identifier,
        UnifiedActionContext $context
    ): ActionResult {
        $locale = $this->locale();
        $yesToken = $locale->lexicon('intent.confirm', default: ['yes'])[0] ?? 'yes';
        $noToken = $locale->lexicon('intent.reject', default: ['no'])[0] ?? 'no';

        Log::channel('ai-engine')->info('Handling DELETE operation', [
            'entity' => $entity,
            'identifier' => $identifier,
        ]);

        // Step 1: Find the entity to delete
        if (!$identifier) {
            return ActionResult::needsUserInput(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.delete.which_entity',
                    "Which {$entity} would you like to delete? Please provide the name or ID.",
                    ['entity' => $entity]
                )
            );
        }

        // Step 2: Find the entity
        $modelClass = $this->getModelClass($entity);
        if (!$modelClass) {
            return ActionResult::failure(error: $this->t(
                'ai-engine::runtime.intelligent_crud.unknown_entity_type',
                "Unknown entity type: {$entity}",
                ['entity' => $entity]
            ));
        }

        $entityRecord = $this->findEntity($modelClass, $identifier);

        if (!$entityRecord) {
            return ActionResult::needsUserInput(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.entity_not_found_short',
                    "I couldn't find a {$entity} matching '{$identifier}'. Could you provide more details?",
                    ['entity' => $entity, 'identifier' => (string) $identifier]
                )
            );
        }

        // Step 3: Confirm deletion
        $confirmPending = $context->get('crud_delete_confirm_pending');

        if (!$confirmPending) {
            $context->set('crud_delete_confirm_pending', true);
            $context->set('crud_entity_to_delete', $entityRecord->toArray());
            $context->set('crud_entity_id', $entityRecord->id);

            return ActionResult::needsUserInput(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.delete.confirm',
                    "⚠️ Are you sure you want to delete {$entity} '{$entityRecord->name}'? This action cannot be undone. ({$yesToken}/{$noToken})",
                    [
                        'entity' => $entity,
                        'name' => (string) ($entityRecord->name ?? ''),
                        'yes' => $yesToken,
                        'no' => $noToken,
                    ]
                )
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

        if ($locale->isLexiconMatch($lastMessage, 'intent.confirm')
            || $locale->startsWithLexicon($lastMessage, 'intent.confirm')) {
            // Perform deletion
            try {
                $entityRecord->delete();

                $context->forget('crud_delete_confirm_pending');
                $context->forget('crud_entity_to_delete');
                $context->forget('crud_entity_id');

                return ActionResult::success(
                    message: $this->t(
                        'ai-engine::runtime.intelligent_crud.delete.success',
                        "✅ {$entity} '{$entityRecord->name}' has been deleted successfully.",
                        ['entity' => $entity, 'name' => (string) ($entityRecord->name ?? '')]
                    ),
                    data: ['deleted_id' => $entityRecord->id]
                );
            } catch (\Exception $e) {
                return ActionResult::failure(
                    error: $this->t(
                        'ai-engine::runtime.intelligent_crud.delete.failed',
                        "Failed to delete {$entity}: " . $e->getMessage(),
                        ['entity' => $entity, 'error' => $e->getMessage()]
                    )
                );
            }
        } else {
            // Cancelled
            $context->forget('crud_delete_confirm_pending');
            $context->forget('crud_entity_to_delete');

            return ActionResult::success(
                message: $this->t(
                    'ai-engine::runtime.intelligent_crud.delete.cancelled',
                    "Deletion cancelled. The {$entity} was not deleted.",
                    ['entity' => $entity]
                )
            );
        }
    }

    protected function t(string $key, string $fallback = '', array $replace = []): string
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

        return strtr(
            $fallback,
            array_map(
                static fn ($value): string => (string) $value,
                array_combine(
                    array_map(static fn ($name): string => ':' . $name, array_keys($replace)),
                    array_values($replace)
                ) ?: []
            )
        );
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
     * Get model class from entity name dynamically
     */
    protected function getModelClass(string $entity): ?string
    {
        // Try to discover from registered AI model metadata.
        $modelRegistry = $this->discoverModelEntities();

        $entityLower = strtolower($entity);
        if (isset($modelRegistry[$entityLower])) {
            return $modelRegistry[$entityLower];
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
    protected function discoverModelEntities(): array
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
                // Include models intended for guided collection.
                if (in_array(($model['strategy'] ?? ''), ['guided_flow', 'quick_action'], true)) {
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
                // Include models intended for guided collection.
                if (in_array(($model['strategy'] ?? ''), ['guided_flow', 'quick_action'], true)) {
                    $entityName = $model['name'];
                    $entityKey = strtolower($entityName);

                    // Use description from AI config (from model's getRAGDescription or initializeAI)
                    $description = $model['description'] ?? $this->t(
                        'ai-engine::runtime.intelligent_crud.default_entity_description',
                        ':entity items',
                        ['entity' => $entityName]
                    );

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
            $prompt = $this->locale()->renderPromptTemplate(
                'intelligent_crud/extract_update_fields',
                [
                    'user_message' => $message,
                    'entity' => $entity,
                    'current_values_json' => json_encode($entityRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ],
                $this->localeCode()
            );
            if ($prompt === '') {
                $prompt = $this->t(
                    'ai-engine::runtime.intelligent_crud.prompts.extract_update_fields.fallback',
                    '',
                    [
                        'user_message' => $message,
                        'entity' => $entity,
                        'current_values_json' => json_encode($entityRecord->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    ]
                );
            }

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $fields = json_decode($response->getContent(), true);
            return $fields ?? [];

        } catch (\Exception $e) {
            Log::error('Extract update fields failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
}
