<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use Illuminate\Support\Facades\Log;

/**
 * Smart Action Service
 *
 * Generates executable actions with AI-powered parameter extraction.
 * Actions are pre-filled with all required data extracted from context.
 */
class SmartActionService
{
    protected array $actionDefinitions = [];

    public function __construct(
        protected ?AIEngineService $aiService = null
    ) {
        $this->registerDefaultActions();
    }

    /**
     * Register default action definitions
     */
    protected function registerDefaultActions(): void
    {
        // Email Reply Action
        $this->registerAction('reply_email', [
            'label' => '✉️ Reply to Email',
            'description' => 'Draft and send a reply to this email',
            'context_triggers' => ['email', 'inbox', 'message', 'from:', 'subject:'],
            'required_params' => ['to_email', 'subject', 'original_content'],
            'optional_params' => ['draft_body', 'cc', 'bcc'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractEmailParams($content, $sources, $metadata);
            },
            'executor' => 'email.reply',
        ]);

        // Forward Email Action
        $this->registerAction('forward_email', [
            'label' => '↗️ Forward Email',
            'description' => 'Forward this email to someone',
            'context_triggers' => ['email', 'forward', 'share'],
            'required_params' => ['original_subject', 'original_content'],
            'optional_params' => ['to_email', 'note'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractForwardParams($content, $sources, $metadata);
            },
            'executor' => 'email.forward',
        ]);

        // Create Calendar Event
        $this->registerAction('create_event', [
            'label' => '📅 Create Calendar Event',
            'description' => 'Add this to your calendar',
            'context_triggers' => ['meeting', 'schedule', 'calendar', 'appointment', 'call', 'tomorrow', 'next week'],
            'required_params' => ['title'],
            'optional_params' => ['date', 'time', 'duration', 'location', 'attendees', 'description'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractCalendarParams($content, $sources, $metadata);
            },
            'executor' => 'calendar.create',
        ]);

        // Create Task
        $this->registerAction('create_task', [
            'label' => '✅ Create Task',
            'description' => 'Create a task from this content',
            'context_triggers' => ['task', 'todo', 'reminder', 'follow up', 'action item', 'deadline'],
            'required_params' => ['title'],
            'optional_params' => ['due_date', 'priority', 'description', 'assignee'],
            'extractor' => function ($content, $sources, $metadata) {
                return $this->extractTaskParams($content, $sources, $metadata);
            },
            'executor' => 'task.create',
        ]);

        // Summarize Content
        $this->registerAction('summarize', [
            'label' => '📝 Summarize',
            'description' => 'Get a summary of this content',
            'context_triggers' => ['long', 'detailed', 'article', 'document', 'report'],
            'required_params' => ['content'],
            'optional_params' => ['length', 'format'],
            'extractor' => function ($content, $sources, $metadata) {
                return ['content' => $content, 'length' => 'brief'];
            },
            'executor' => 'ai.summarize',
        ]);

        // Dynamic Model Actions - Auto-discover from RAG collections
        $this->registerDynamicModelActions();

        // Translate Content
        $this->registerAction('translate', [
            'label' => '🌐 Translate',
            'description' => 'Translate this content',
            'context_triggers' => [],
            'required_params' => ['content'],
            'optional_params' => ['target_language', 'source_language'],
            'extractor' => function ($content, $sources, $metadata) {
                return ['content' => $content, 'target_language' => 'en'];
            },
            'executor' => 'ai.translate',
        ]);
    }

    /**
     * Register a custom action
     */
    public function registerAction(string $id, array $definition): void
    {
        $this->actionDefinitions[$id] = array_merge([
            'id' => $id,
            'label' => $id,
            'description' => '',
            'context_triggers' => [],
            'required_params' => [],
            'optional_params' => [],
            'extractor' => null,
            'executor' => null,
        ], $definition);
    }

    /**
     * Register dynamic model actions from RAG collections
     */
    protected function registerDynamicModelActions(): void
    {
        try {
            // Get all RAG collections with remote model information
            $ragDiscovery = app(\LaravelAIEngine\Services\RAG\RAGCollectionDiscovery::class);
            $collections = $ragDiscovery->discover();

            // Get remote model information from federated nodes
            $remoteModelsInfo = $this->getRemoteModelsInfo();

            // Merge collections with remote models (remote models might not be in RAG collections)
            $allModels = array_unique(array_merge($collections, array_keys($remoteModelsInfo)));

            foreach ($allModels as $modelClass) {
                $hasExecuteAI = false;
                $expectedFormat = null;
                
                Log::channel('ai-engine')->debug('Processing model for registration', [
                    'model' => $modelClass,
                    'class_exists' => class_exists($modelClass),
                    'in_remote_info' => isset($remoteModelsInfo[$modelClass])
                ]);

                // Check if model exists locally
                if (class_exists($modelClass)) {
                    $reflection = new \ReflectionClass($modelClass);

                    // Check for executeAI or initializeAI method locally
                    if (!$reflection->hasMethod('executeAI') && !$reflection->hasMethod('initializeAI')) {
                        continue;
                    }

                    $hasExecuteAI = true;
                    $expectedFormat = $this->getModelExpectedFormat($modelClass);
                }
                // Check if model has executeAI on remote node
                elseif (isset($remoteModelsInfo[$modelClass])) {
                    $modelInfo = $remoteModelsInfo[$modelClass];

                    if (in_array('executeAI', $modelInfo['methods'] ?? []) || in_array('initializeAI', $modelInfo['methods'] ?? [])) {
                        $hasExecuteAI = true;
                        $expectedFormat = $modelInfo['format'] ?? $this->getDefaultFormat();
                    }
                }

                if (!$hasExecuteAI) {
                    continue;
                }

                // Get model name for action
                $modelName = class_basename($modelClass);
                $actionId = 'create_' . strtolower($modelName);

                // Use expected format or default
                if (!$expectedFormat) {
                    $expectedFormat = $this->getDefaultFormat();
                }

                // Get triggers from format or generate defaults
                // Note: Model actions use intent-based matching, not trigger matching
                // Triggers are kept for backward compatibility and fallback scenarios
                $triggers = $expectedFormat['triggers'] ?? [];
                if (empty($triggers)) {
                    $triggers = $this->generateTriggersForModel($modelName);
                }

                // Register action for this model
                $this->registerAction($actionId, [
                    'label' => "🎯 Create {$modelName}",
                    'description' => "Create a new {$modelName} from conversation",
                    'context_triggers' => $triggers,
                    'required_params' => $expectedFormat['required'] ?? [],
                    'optional_params' => $expectedFormat['optional'] ?? [],
                    'expected_format' => $expectedFormat,
                    'model_class' => $modelClass,
                    'extractor' => function ($content, $sources, $metadata) use ($modelClass, $expectedFormat, $actionId) {
                        Log::channel('ai-engine')->debug('Extractor called', [
                            'action_id' => $actionId,
                            'model' => $modelClass,
                            'content_length' => strlen($content),
                            'metadata_keys' => array_keys($metadata)
                        ]);

                        try {
                            $result = $this->extractModelParams($modelClass, $content, $sources, $metadata, $expectedFormat);

                            Log::channel('ai-engine')->debug('Extractor result', [
                                'action_id' => $actionId,
                                'result' => $result
                            ]);

                            return $result;
                        } catch (\Exception $e) {
                            Log::channel('ai-engine')->error('Extractor failed', [
                                'action_id' => $actionId,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                            return [];
                        }
                    },
                    'executor' => 'model.dynamic',
                ]);

                // Store model class for later reference
                $this->actionDefinitions[$actionId]['model_class'] = $modelClass;
                $this->actionDefinitions[$actionId]['is_local'] = class_exists($modelClass);

                Log::channel('ai-engine')->info('Registered dynamic model action', [
                    'model' => $modelClass,
                    'action_id' => $actionId,
                    'required_params' => $expectedFormat['required'] ?? [],
                    'is_local' => class_exists($modelClass),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to register dynamic model actions: ' . $e->getMessage());
        }
    }

    /**
     * Get remote models information from federated nodes
     */
    protected function getRemoteModelsInfo(): array
    {
        // Cache remote models info for 5 minutes to avoid repeated HTTP calls
        return \Illuminate\Support\Facades\Cache::remember('ai_engine_remote_models', 300, function () {
            $remoteModels = [];

            try {
                // Check if AINode model exists
                if (!class_exists(\LaravelAIEngine\Models\AINode::class)) {
                    return $remoteModels;
                }

                $nodes = \LaravelAIEngine\Models\AINode::where('status', 'active')->get();

                foreach ($nodes as $node) {
                    try {
                        // Use shared secret from config or node's API key
                        $authToken = config('ai-engine.nodes.shared_secret') ?? $node->api_key;

                        $response = \Illuminate\Support\Facades\Http::withoutVerifying()
                            ->timeout(5)
                            ->withToken($authToken)
                            ->get($node->url . '/api/ai-engine/collections');

                        if ($response->successful()) {
                            $data = $response->json();
                            $collections = $data['collections'] ?? [];

                            foreach ($collections as $collection) {
                                $className = $collection['class'] ?? null;
                                if ($className) {
                                    $remoteModels[$className] = [
                                        'methods' => $collection['methods'] ?? [],
                                        'format' => $collection['format'] ?? null,
                                        'node' => $node->name,
                                    ];
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::debug('Failed to get collections from node', [
                            'node' => $node->name,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get remote models info: ' . $e->getMessage());
            }

            return $remoteModels;
        });
    }

    /**
     * Get default format for models without initializeAI
     * Returns empty format - models should define their own fields
     */
    protected function getDefaultFormat(): array
    {
        return [
            'required' => [],
            'optional' => [],
        ];
    }

    /**
     * Get expected format from model (initializeAI or fillable)
     */
    protected function getModelExpectedFormat(string $modelClass): array
    {
        Log::channel('ai-engine')->debug('getModelExpectedFormat called', [
            'model' => $modelClass,
            'class_exists' => class_exists($modelClass)
        ]);
        
        try {
            $reflection = new \ReflectionClass($modelClass);

            // Check for initializeAI method
            if ($reflection->hasMethod('initializeAI')) {
                $method = $reflection->getMethod('initializeAI');
                $config = null;

                if ($method->isStatic()) {
                    $config = $modelClass::initializeAI();
                } else {
                    // Non-static method, instantiate and call
                    $model = new $modelClass();
                    $config = $model->initializeAI();
                }

                // If config has fields, convert to expected format
                if (isset($config['fields'])) {
                    $required = [];
                    $optional = [];
                    $fieldDefinitions = [];

                    foreach ($config['fields'] as $fieldName => $fieldConfig) {
                        $fieldDefinitions[$fieldName] = $fieldConfig;

                        if ($fieldConfig['required'] ?? false) {
                            $required[] = $fieldName;
                        } else {
                            $optional[] = $fieldName;
                        }
                    }

                    $result = [
                        'required' => $required,
                        'optional' => $optional,
                        'all_fields' => array_keys($config['fields']),
                        'fields' => $fieldDefinitions,
                    ];
                    
                    Log::channel('ai-engine')->debug('getModelExpectedFormat result', [
                        'model' => $modelClass,
                        'all_fields' => $result['all_fields'],
                        'required' => $result['required'],
                        'optional' => $result['optional']
                    ]);

                    // Include critical_fields if defined in model's initializeAI
                    if (isset($config['critical_fields'])) {
                        $result['critical_fields'] = $config['critical_fields'];
                    }
                    
                    // Include extraction_hints if defined in model's initializeAI
                    if (isset($config['extraction_format'])) {
                        $result['extraction_hints'] = $config['extraction_format'];
                    }
                    
                    return $result;
                }
                
                return $config;
            }

            // Fallback to fillable fields
            $model = new $modelClass();
            $fillable = $model->getFillable();

            // Try to determine required vs optional from validation rules if available
            $required = [];
            $optional = [];

            if (method_exists($model, 'rules')) {
                $rules = $model->rules();
                foreach ($fillable as $field) {
                    if (isset($rules[$field]) && str_contains($rules[$field], 'required')) {
                        $required[] = $field;
                    } else {
                        $optional[] = $field;
                    }
                }
            } else {
                // Assume first 2-3 fields are required, rest optional
                $required = array_slice($fillable, 0, min(2, count($fillable)));
                $optional = array_slice($fillable, min(2, count($fillable)));
            }

            return [
                'required' => $required,
                'optional' => $optional,
                'all_fields' => $fillable,
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to get expected format for {$modelClass}: " . $e->getMessage());
            return ['required' => [], 'optional' => []];
        }
    }

    /**
     * Generate context triggers for model
     */
    protected function generateTriggersForModel(string $modelName): array
    {
        $lower = strtolower($modelName);
        $triggers = [
            $lower,
            "create {$lower}",
            "add {$lower}",
            "new {$lower}",
        ];

        return array_unique($triggers);
    }

    /**
     * Extract parameters for any model dynamically
     */
    protected function extractModelParams(string $modelClass, string $content, array $sources, array $metadata, array $expectedFormat): array
    {
        // Build allFields from either old format (required/optional arrays) or new format (fields object)
        if (isset($expectedFormat['all_fields'])) {
            $allFields = $expectedFormat['all_fields'];
        } else {
            $allFields = array_merge(
                $expectedFormat['required'] ?? [],
                $expectedFormat['optional'] ?? []
            );
        }
        
        Log::channel('ai-engine')->debug('Expected format structure', [
            'model' => $modelClass,
            'has_all_fields' => isset($expectedFormat['all_fields']),
            'has_required' => isset($expectedFormat['required']),
            'has_optional' => isset($expectedFormat['optional']),
            'has_fields' => isset($expectedFormat['fields']),
            'all_fields' => $allFields
        ]);

        // Use AI to extract fields from conversation
        $conversationHistory = $metadata['conversation_history'] ?? [];
        $userId = $metadata['user_id'] ?? null;

        // Build context from last 5 messages
        $context = '';
        $recentMessages = array_slice($conversationHistory, -5);
        foreach ($recentMessages as $msg) {
            $role = $msg['role'] ?? 'user';
            $msgContent = $msg['content'] ?? '';
            $context .= "{$role}: {$msgContent}\n";
        }
        $context .= "user: {$content}\n";

        // Check if model supports function calling schema
        $useFunctionCalling = method_exists($modelClass, 'getFunctionSchema');
        $extracted = null;
        
        if ($useFunctionCalling) {
            // Use function calling for strict type safety
            $functionSchema = $modelClass::getFunctionSchema();
            
            Log::channel('ai-engine')->debug('Using function calling for extraction', [
                'model' => $modelClass,
                'function' => $functionSchema['name'] ?? 'unknown'
            ]);

            try {
                $aiRequest = (new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: "Extract data from: {$content}",
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    systemPrompt: 'You are a data extraction assistant. Use the provided function to extract structured data.',
                    maxTokens: 500
                ))->withFunctions([$functionSchema], ['name' => $functionSchema['name']]);

                $response = $this->aiService->generate($aiRequest);
                
                // Extract function call arguments
                if (isset($response->functionCall) && isset($response->functionCall['arguments'])) {
                    $extracted = json_decode($response->functionCall['arguments'], true);
                    
                    Log::channel('ai-engine')->debug('Function calling extraction result', [
                        'model' => $modelClass,
                        'extracted' => $extracted
                    ]);
                } else {
                    // Fallback to content parsing
                    $aiContent = $response->getContent();
                    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiContent, $matches)) {
                        $aiContent = $matches[1];
                    }
                    $extracted = json_decode($aiContent, true);
                }
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('Function calling failed, falling back to prompt-based extraction', [
                    'model' => $modelClass,
                    'error' => $e->getMessage()
                ]);
                $extracted = null;
            }
        }
        
        if ($extracted === null) {
            // Fallback to prompt-based extraction
            $prompt = "Extract data from the user's message for creating a " . class_basename($modelClass) . ".\n\n";
            $prompt .= "Message: \"{$content}\"\n\n";
            
            // Include field descriptions from model's initializeAI() if available
            $fields = $expectedFormat['fields'] ?? [];
            if (!empty($fields)) {
                $prompt .= "Available fields with descriptions:\n";
                foreach ($fields as $fieldName => $fieldInfo) {
                    $description = is_array($fieldInfo) ? ($fieldInfo['description'] ?? $fieldName) : $fieldInfo;
                    $type = is_array($fieldInfo) ? ($fieldInfo['type'] ?? 'string') : 'string';
                    $prompt .= "- {$fieldName} ({$type}): {$description}\n";
                }
                $prompt .= "\n";
            } else {
                // Fallback to simple field list if no descriptions available
                $prompt .= "Fields to extract: " . implode(', ', $allFields) . "\n\n";
            }
            
            Log::channel('ai-engine')->debug('Extraction prompt fields', [
                'model' => $modelClass,
                'all_fields' => $allFields,
                'has_field_descriptions' => !empty($fields),
                'content' => $content
            ]);

            // Add model-specific extraction hints if available
            $extractionHints = $expectedFormat['extraction_hints'] ?? [];

            Log::channel('ai-engine')->debug('Extraction hints check', [
                'model' => $modelClass,
                'has_hints' => !empty($extractionHints),
                'hints' => $extractionHints
            ]);

            if (!empty($extractionHints)) {
                $prompt .= "Model-Specific Instructions:\n";
                foreach ($extractionHints as $field => $hints) {
                    if (is_array($hints)) {
                        $prompt .= "For '{$field}' field:\n";
                        foreach ($hints as $hint) {
                            $prompt .= "  - {$hint}\n";
                        }
                    }
                }
                $prompt .= "\n";
            }

            $prompt .= "Instructions:\n";
            $prompt .= "- Extract ALL field values mentioned in the message\n";
            $prompt .= "- Use the EXACT field names listed above\n";
            $prompt .= "- Match field names to their semantic meaning based on the descriptions provided\n";
            $prompt .= "- For relationship fields (ending in '_id'), extract the identifying value (name, email, etc.)\n";
            $prompt .= "- Use null only for fields NOT mentioned in the message\n\n";
            $prompt .= "Return ONLY valid JSON:";

            try {
                $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    systemPrompt: 'You are a data extraction assistant. Extract structured data from conversations.',
                    maxTokens: 500
                );

                $response = $this->aiService->generate($aiRequest);
                $aiContent = $response->getContent();

            Log::channel('ai-engine')->debug('AI extraction raw response', [
                'model' => $modelClass,
                'content' => $aiContent
            ]);

            // Extract JSON from markdown code blocks if present
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $aiContent, $matches)) {
                $aiContent = $matches[1];
            }

            $extracted = json_decode($aiContent, true);
            } catch (\Exception $e) {
                Log::channel('ai-engine')->warning('AI extraction failed, using regex fallback', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'model' => $modelClass,
                    'content' => $content
                ]);
            }
        }

        // Post-process extracted data (common for both function calling and prompt-based)
        if (is_array($extracted)) {
            // Filter out null values
            $extracted = array_filter($extracted, fn($v) => $v !== null);

            Log::channel('ai-engine')->debug('AI extraction result', [
                'model' => $modelClass,
                'extracted' => $extracted,
                'all_fields' => $allFields
            ]);

            // Enhance with regex fallback for any missing fields
            $regexExtracted = $this->regexExtractFields($content, $allFields);
            foreach ($regexExtracted as $field => $value) {
                if (empty($extracted[$field]) && !empty($value)) {
                    $extracted[$field] = $value;
                    Log::channel('ai-engine')->debug('Enhanced extraction with regex fallback', [
                        'field' => $field,
                        'value' => $value
                    ]);
                }
            }

            Log::channel('ai-engine')->debug('Final extracted params', [
                'model' => $modelClass,
                'params' => $extracted
            ]);

            // Resolve relationship fields intelligently
            $extracted = $this->resolveRelationships($modelClass, $extracted, $userId);

            return $extracted;
        }

        // Fallback to basic regex extraction
        $regexResult = $this->regexExtractFields($content, $allFields);

        Log::channel('ai-engine')->debug('Using regex fallback extraction', [
            'model' => $modelClass,
            'extracted' => $regexResult
        ]);

        return $regexResult;
    }

    /**
     * Resolve relationship fields intelligently
     * Automatically creates related records if they don't exist
     */
    protected function resolveRelationships(string $modelClass, array $data, $userId = null): array
    {
        // For remote models, pass relationship data as-is with special marker
        // The remote node will handle relationship resolution
        if (!class_exists($modelClass)) {
            Log::channel('ai-engine')->debug('Preparing relationship data for remote model', [
                'model' => $modelClass,
                'data' => $data
            ]);

            // Mark fields ending with _id that have string values as relationships to resolve
            $relationshipsToResolve = [];
            foreach ($data as $key => $value) {
                if (str_ends_with($key, '_id') && !is_numeric($value) && is_string($value)) {
                    $relationshipsToResolve[$key] = $value;
                }
            }

            if (!empty($relationshipsToResolve)) {
                $data['_resolve_relationships'] = $relationshipsToResolve;
                Log::channel('ai-engine')->info('Marked relationships for remote resolution', [
                    'relationships' => $relationshipsToResolve
                ]);
            }

            return $data;
        }

        // Local model - resolve relationships locally
        try {
            $model = new $modelClass();

            foreach ($data as $key => $value) {
                // Check if this is a relationship field (ends with _id but value is not numeric)
                if (str_ends_with($key, '_id') && !is_numeric($value) && is_string($value)) {
                    // Get the relationship name (remove _id suffix)
                    $relationName = substr($key, 0, -3);

                    // Check if model has this relationship
                    if (method_exists($model, $relationName)) {
                        $relation = $model->$relationName();
                        $relatedClass = get_class($relation->getRelated());

                        Log::channel('ai-engine')->info('Resolving relationship locally', [
                            'field' => $key,
                            'relation' => $relationName,
                            'related_class' => $relatedClass,
                            'search_value' => $value
                        ]);

                        // Try to find or create the related record
                        $relatedId = $this->findOrCreateRelated($relatedClass, $value, $userId);

                        if ($relatedId) {
                            $data[$key] = $relatedId;
                            unset($data[$relationName]); // Remove the name field if it exists
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Relationship resolution failed: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * Find or create related record intelligently
     */
    protected function findOrCreateRelated(string $relatedClass, string $searchValue, $userId = null): ?int
    {
        try {
            // Check if model is vectorizable (has vector search capability)
            $isVectorizable = method_exists($relatedClass, 'vectorSearch');

            if ($isVectorizable) {
                // Use AI semantic search
                Log::channel('ai-engine')->info('Using vector search for relation', [
                    'class' => $relatedClass,
                    'query' => $searchValue
                ]);

                $filters = [];
                if ($userId) {
                    // Respect user scope if model has user_id
                    $model = new $relatedClass();
                    if (in_array('user_id', $model->getFillable())) {
                        $filters['user_id'] = $userId;
                    }
                }

                $results = $relatedClass::vectorSearch($searchValue, $filters, 1);

                if (!empty($results)) {
                    Log::channel('ai-engine')->info('Found via vector search', [
                        'id' => $results[0]->id,
                        'score' => $results[0]->_score ?? null
                    ]);
                    return $results[0]->id;
                }
            } else {
                // Fall back to SQL search
                Log::channel('ai-engine')->info('Using SQL search for relation', [
                    'class' => $relatedClass,
                    'query' => $searchValue
                ]);

                $query = $relatedClass::query();

                // Respect user scope
                if ($userId) {
                    $model = new $relatedClass();
                    if (in_array('user_id', $model->getFillable())) {
                        $query->where('user_id', $userId);
                    }
                }

                // Search in first fillable field (generic approach)
                $model = new $relatedClass();
                $fillable = $model->getFillable();
                
                if (!empty($fillable)) {
                    $searchField = $fillable[0]; // Use first fillable field
                    $record = $query->where($searchField, 'LIKE', "%{$searchValue}%")->first();

                    if ($record) {
                        Log::channel('ai-engine')->info('Found via SQL search', [
                            'id' => $record->id,
                            'search_field' => $searchField
                        ]);
                        return $record->id;
                    }
                }
            }

            // Not found - try to create if model allows it
            if (method_exists($relatedClass, 'createFromAI')) {
                $model = new $relatedClass();
                $fillable = $model->getFillable();
                $firstField = !empty($fillable) ? $fillable[0] : 'name';
                
                Log::channel('ai-engine')->info('Creating new related record', [
                    'class' => $relatedClass,
                    'field' => $firstField,
                    'value' => $searchValue
                ]);

                $data = [$firstField => $searchValue];
                if ($userId && in_array('user_id', $model->getFillable())) {
                    $data['user_id'] = $userId;
                }

                $newRecord = $relatedClass::createFromAI($data);
                return $newRecord->id ?? $newRecord['id'] ?? null;
            }

            // Try simple create as last resort
            $model = new $relatedClass();
            $fillable = $model->getFillable();
            
            if (!empty($fillable)) {
                $firstField = $fillable[0];
                $data = [$firstField => $searchValue];
                
                if ($userId && in_array('user_id', $model->getFillable())) {
                    $data['user_id'] = $userId;
                }

                $newRecord = $relatedClass::create($data);

                Log::channel('ai-engine')->info('Created new related record', [
                    'class' => $relatedClass,
                    'id' => $newRecord->id
                ]);

                return $newRecord->id;
            }

        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('Failed to find/create related record', [
                'class' => $relatedClass,
                'search' => $searchValue,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Regex fallback for field extraction
     * Generic pattern matching - no hardcoded field names
     */
    protected function regexExtractFields(string $content, array $fields): array
    {
        $extracted = [];

        // Generic numeric extraction for any field
        foreach ($fields as $field) {
            // Skip if already extracted
            if (isset($extracted[$field])) {
                continue;
            }
            
            // Try to extract numbers for fields that might be numeric
            if (preg_match('/\b' . preg_quote($field, '/') . '\s*[:\s]+\$?([\d,]+\.?\d*)/i', $content, $matches)) {
                $extracted[$field] = (float) str_replace(',', '', $matches[1]);
            }
        }

        return $extracted;
    }

    /**
     * Generate smart actions based on content and context
     */
    public function generateSmartActions(
        string $content,
        array $sources = [],
        array $metadata = []
    ): array {
        // Always re-register dynamic model actions to ensure they're available
        // This is necessary because the service is a singleton and action definitions
        // might not persist between requests or might be registered after service instantiation
        $this->registerDynamicModelActions();

        Log::channel('ai-engine')->debug('Action definitions available', [
            'count' => count($this->actionDefinitions),
            'actions' => array_keys($this->actionDefinitions)
        ]);

        $actions = [];

        // Get intent analysis from metadata
        $intentAnalysis = $metadata['intent_analysis'] ?? null;

        foreach ($this->actionDefinitions as $id => $definition) {
            // Use intent-based matching for dynamic model actions if intent analysis is available
            $isModelAction = isset($definition['model_class']);
            $triggersMatch = false;

            if ($isModelAction && $intentAnalysis) {
                // For model actions, use intent analysis to detect creation requests
                $triggersMatch = $this->matchesIntentForModelAction($intentAnalysis, $definition);

                Log::channel('ai-engine')->debug('Intent-based action match', [
                    'action_id' => $id,
                    'intent' => $intentAnalysis['intent'],
                    'confidence' => $intentAnalysis['confidence'],
                    'matches' => $triggersMatch
                ]);
            } else {
                // For non-model actions, use traditional trigger matching
                $triggersMatch = $this->matchesTriggers($content, $definition['context_triggers']);

                Log::channel('ai-engine')->debug('Trigger-based action match', [
                    'action_id' => $id,
                    'triggers' => $definition['context_triggers'],
                    'content' => substr($content, 0, 100),
                    'matches' => $triggersMatch
                ]);
            }

            if ($triggersMatch) {
                // Extract parameters using the extractor
                $params = [];
                if (is_callable($definition['extractor'])) {
                    $params = call_user_func($definition['extractor'], $content, $sources, $metadata);
                }

                Log::channel('ai-engine')->debug('Action trigger matched', [
                    'action_id' => $id,
                    'label' => $definition['label'],
                    'triggers' => $definition['context_triggers'],
                    'extracted_params' => $params,
                    'required_params' => $definition['required_params'],
                ]);

                // Check if required params are present
                $hasRequired = $this->hasRequiredParams($params, $definition['required_params']);

                // For model actions, check conversational guidance and business logic fields
                $isModelAction = isset($definition['model_class']);
                $shouldAskForMissing = false;
                $missingFields = [];

                if ($isModelAction) {
                    $modelClass = $definition['model_class'];

                    Log::channel('ai-engine')->debug('Checking model action for missing fields', [
                        'action_id' => $id,
                        'model' => $modelClass,
                        'has_expected_format' => isset($definition['expected_format']),
                        'expected_format_keys' => array_keys($definition['expected_format'] ?? []),
                        'has_config_critical_fields' => config("ai-engine.smart_actions.model_critical_fields.{$modelClass}") !== null,
                    ]);

                    // Check for critical fields using the action definition's expected format
                    $missingFields = $this->getMissingCriticalFieldsFromDefinition($params, $definition, $modelClass);

                    Log::channel('ai-engine')->debug('Missing fields check result', [
                        'action_id' => $id,
                        'missing_fields' => $missingFields,
                        'params' => array_keys($params),
                    ]);

                    if (!empty($missingFields)) {
                        $shouldAskForMissing = true;

                        Log::channel('ai-engine')->info('Model missing critical fields', [
                            'action_id' => $id,
                            'model' => $modelClass,
                            'missing_fields' => $missingFields,
                            'extracted_params' => array_keys($params),
                        ]);
                    } elseif (!$hasRequired) {
                        // For models without critical fields, use standard required check
                        $missingFields = $this->getMissingRequiredFields($params, $definition, $modelClass);
                    }
                }

                // Only add action if required params can be extracted OR if we should ask for missing
                if ($hasRequired && !$shouldAskForMissing) {
                    // Build confirmation description from params
                    $description = $this->buildConfirmationDescription($definition, $params);

                    $actions[] = new InteractiveAction(
                        id: $id . '_' . uniqid(),
                        type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                        label: $definition['label'],
                        description: $description,
                        data: [
                            'action' => $id,
                            'executor' => $definition['executor'],
                            'model_class' => $definition['model_class'] ?? null,
                            'params' => $params,
                            'ready_to_execute' => true,
                        ]
                    );

                    Log::channel('ai-engine')->info('Action added to response', [
                        'action_id' => $id,
                        'label' => $definition['label'],
                        'params' => $params,
                    ]);
                } elseif ($shouldAskForMissing) {
                    // Create action with partial params but mark it as needing more info
                    $description = $this->buildConfirmationDescription($definition, $params);
                    $description .= "\n\n⚠️ **Missing Information:** " . implode(', ', $missingFields);

                    $actions[] = new InteractiveAction(
                        id: $id . '_' . uniqid(),
                        type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                        label: $definition['label'] . ' (Incomplete)',
                        description: $description,
                        data: [
                            'action' => $id,
                            'executor' => $definition['executor'],
                            'model_class' => $definition['model_class'] ?? null,
                            'params' => $params,
                            'ready_to_execute' => false,
                            'missing_fields' => $missingFields,
                        ]
                    );

                    Log::channel('ai-engine')->info('Action added with missing fields warning', [
                        'action_id' => $id,
                        'missing_fields' => $missingFields,
                        'partial_params' => $params,
                    ]);
                } else {
                    Log::channel('ai-engine')->debug('Action skipped - missing required params', [
                        'action_id' => $id,
                        'label' => $definition['label'],
                        'params' => $params,
                        'required' => $definition['required_params'],
                    ]);
                }
            }
        }

        return $actions;
    }

    /**
     * Check if intent analysis matches a model action
     * Uses AI-detected intent to intelligently match creation requests
     */
    protected function matchesIntentForModelAction(array $intentAnalysis, array $actionDefinition): bool
    {
        $intent = $intentAnalysis['intent'] ?? '';
        $confidence = $intentAnalysis['confidence'] ?? 0;

        // Language-agnostic matching: rely on AI's intent classification
        // The AI analyzes the message in any language and returns standardized intent
        // 'new_request' means user wants to create/add something new
        if ($intent === 'new_request' && $confidence >= 0.8) {
            return true;
        }

        return false;
    }

    /**
     * Check if content matches any triggers
     */
    protected function matchesTriggers(string $content, array $triggers): bool
    {
        if (empty($triggers)) {
            return false;
        }

        $contentLower = strtolower($content);
        foreach ($triggers as $trigger) {
            $triggerLower = strtolower($trigger);
            if (str_contains($contentLower, $triggerLower)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all required parameters are present
     */
    protected function hasRequiredParams(array $params, array $required): bool
    {
        foreach ($required as $param) {
            if (!isset($params[$param]) || $params[$param] === null || $params[$param] === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Build confirmation description from extracted parameters
     */
    protected function buildConfirmationDescription(array $definition, array $params): string
    {
        $modelName = $definition['model_name'] ?? 'Record';
        $description = "**Confirm {$modelName} Creation:**\n\n";

        // Generic parameter display
        foreach ($params as $key => $value) {
            if ($key === 'items' && is_array($value)) {
                // Special handling for items array to show details
                $description .= "**Items:**\n";
                foreach ($value as $index => $item) {
                    $itemNum = $index + 1;
                    $itemName = $item['name'] ?? $item['item'] ?? $item['product_name'] ?? 'Item';
                    $itemPrice = $item['amount'] ?? $item['price'] ?? 0;
                    $itemQty = $item['quantity'] ?? 1;
                    $description .= "  {$itemNum}. {$itemName} - \${$itemPrice} × {$itemQty}\n";
                }
            } elseif (is_array($value) && $key !== '_resolve_relationships') {
                $description .= "**" . ucfirst(str_replace('_', ' ', $key)) . ":** " . count($value) . " items\n";
            } elseif (!is_object($value) && $key !== '_resolve_relationships') {
                $description .= "**" . ucfirst(str_replace('_', ' ', $key)) . ":** {$value}\n";
            }
        }

        return $description;
    }

    /**
     * Extract email parameters from content and sources
     */
    protected function extractEmailParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Try to extract from sources (RAG results)
        foreach ($sources as $source) {
            if (isset($source['metadata'])) {
                $meta = $source['metadata'];
                if (isset($meta['from_email'])) {
                    $params['to_email'] = $meta['from_email'];
                }
                if (isset($meta['subject'])) {
                    $params['subject'] = 'Re: ' . $meta['subject'];
                }
                if (isset($meta['content']) || isset($meta['body'])) {
                    $params['original_content'] = $meta['content'] ?? $meta['body'];
                }
            }
        }

        // Extract email from content using regex
        if (empty($params['to_email'])) {
            if (preg_match('/[\w\.-]+@[\w\.-]+\.\w+/', $content, $matches)) {
                $params['to_email'] = $matches[0];
            }
        }

        // Extract subject from content
        if (empty($params['subject'])) {
            if (preg_match('/subject[:\s]+([^\n]+)/i', $content, $matches)) {
                $params['subject'] = 'Re: ' . trim($matches[1]);
            }
        }

        // Use AI to generate draft reply if available
        if ($this->aiService && !empty($params['original_content'])) {
            try {
                $params['draft_body'] = $this->generateDraftReply($params['original_content']);
            } catch (\Exception $e) {
                Log::warning('Failed to generate draft reply: ' . $e->getMessage());
            }
        }

        return $params;
    }

    /**
     * Extract forward parameters
     */
    protected function extractForwardParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        foreach ($sources as $source) {
            if (isset($source['metadata'])) {
                $meta = $source['metadata'];
                if (isset($meta['subject'])) {
                    $params['original_subject'] = 'Fwd: ' . $meta['subject'];
                }
                if (isset($meta['content']) || isset($meta['body'])) {
                    $params['original_content'] = $meta['content'] ?? $meta['body'];
                }
            }
        }

        return $params;
    }

    /**
     * Extract calendar event parameters
     */
    protected function extractCalendarParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract date/time patterns
        $datePatterns = [
            '/(\d{1,2}\/\d{1,2}\/\d{2,4})/' => 'date',
            '/(\d{4}-\d{2}-\d{2})/' => 'date',
            '/(\d{1,2}:\d{2}\s*(?:am|pm)?)/i' => 'time',
            '/tomorrow/i' => 'date_relative',
            '/next\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)/i' => 'date_relative',
        ];

        foreach ($datePatterns as $pattern => $type) {
            if (preg_match($pattern, $content, $matches)) {
                if ($type === 'date') {
                    $params['date'] = $matches[1];
                } elseif ($type === 'time') {
                    $params['time'] = $matches[1];
                } elseif ($type === 'date_relative') {
                    $params['date'] = $this->parseRelativeDate($matches[0]);
                }
            }
        }

        // Extract title from content (first line or meeting keyword context)
        if (preg_match('/meeting\s+(?:about|for|with|on)\s+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } elseif (preg_match('/schedule\s+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } else {
            // Use first sentence as title
            $params['title'] = strtok($content, ".\n");
        }

        // Extract duration
        if (preg_match('/(\d+)\s*(?:hour|hr|minute|min)/i', $content, $matches)) {
            $params['duration'] = $matches[0];
        }

        // Extract attendees (email addresses)
        preg_match_all('/[\w\.-]+@[\w\.-]+\.\w+/', $content, $emailMatches);
        if (!empty($emailMatches[0])) {
            $params['attendees'] = array_unique($emailMatches[0]);
        }

        return $params;
    }

    /**
     * Extract task parameters
     */
    protected function extractTaskParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract title
        if (preg_match('/(?:task|todo|reminder)[:\s]+([^\n\.]+)/i', $content, $matches)) {
            $params['title'] = trim($matches[1]);
        } else {
            $params['title'] = strtok($content, ".\n");
        }

        // Extract due date
        if (preg_match('/(?:due|by|deadline)[:\s]+([^\n\.]+)/i', $content, $matches)) {
            $params['due_date'] = trim($matches[1]);
        }

        // Extract priority
        if (preg_match('/(?:urgent|high\s*priority|important)/i', $content)) {
            $params['priority'] = 'high';
        } elseif (preg_match('/(?:low\s*priority)/i', $content)) {
            $params['priority'] = 'low';
        } else {
            $params['priority'] = 'normal';
        }

        $params['description'] = $content;

        return $params;
    }


    /**
     * Parse relative date strings
     */
    protected function parseRelativeDate(string $relative): string
    {
        $relative = strtolower($relative);

        if ($relative === 'tomorrow') {
            return date('Y-m-d', strtotime('+1 day'));
        }

        if (preg_match('/next\s+(\w+)/', $relative, $matches)) {
            return date('Y-m-d', strtotime('next ' . $matches[1]));
        }

        return date('Y-m-d');
    }

    /**
     * Check if model has conversational guidance in its AI config
     */
    protected function hasConversationalGuidance(string $modelClass): bool
    {
        try {
            if (!class_exists($modelClass)) {
                return false;
            }

            $reflection = new \ReflectionClass($modelClass);

            // Check for initializeAI method
            if (!$reflection->hasMethod('initializeAI')) {
                return false;
            }

            $method = $reflection->getMethod('initializeAI');
            if (!$method->isStatic()) {
                return false;
            }

            $config = $modelClass::initializeAI();
            $description = $config['description'] ?? '';

            // Check if description contains conversational guidance
            return str_contains($description, 'CONVERSATIONAL GUIDANCE');
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to check conversational guidance', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get missing critical fields from action definition's expected format
     * Works for both local and remote models by using the registered action definition
     */
    protected function getMissingCriticalFieldsFromDefinition(array $params, array $definition, string $modelClass): array
    {
        $missing = [];
        $expectedFormat = $definition['expected_format'] ?? [];

        // First priority: Check configuration for model-specific critical fields
        $configCriticalFields = config("ai-engine.smart_actions.model_critical_fields.{$modelClass}");
        if ($configCriticalFields) {
            foreach ($configCriticalFields as $fieldName => $fieldConfig) {
                // Handle simple array format: ['customer', 'items']
                if (is_numeric($fieldName)) {
                    $fieldName = $fieldConfig;
                    $fieldConfig = ['type' => 'string'];
                }

                // Check if field is satisfied
                $fieldSatisfied = $this->isFieldSatisfied($fieldName, $fieldConfig, $params);

                if (!$fieldSatisfied) {
                    // For relationship fields, add specific sub-fields to missing list
                    if (isset($fieldConfig['fields'])) {
                        foreach ($fieldConfig['fields'] as $subField) {
                            $missing[] = $fieldName . '_' . $subField;
                        }
                    } else {
                        $missing[] = $fieldName;
                    }
                }
            }

            return $missing;
        }

        // Second priority: Check if model defines critical_fields in initializeAI
        if (isset($expectedFormat['critical_fields'])) {
            $criticalFields = $expectedFormat['critical_fields'];

            foreach ($criticalFields as $fieldName => $fieldConfig) {
                // Handle simple array format: ['customer', 'items']
                if (is_numeric($fieldName)) {
                    $fieldName = $fieldConfig;
                    $fieldConfig = ['type' => 'string'];
                }

                // Check if field is satisfied
                $fieldSatisfied = $this->isFieldSatisfied($fieldName, $fieldConfig, $params);

                if (!$fieldSatisfied) {
                    // For relationship fields, add specific sub-fields to missing list
                    if (isset($fieldConfig['fields'])) {
                        foreach ($fieldConfig['fields'] as $subField) {
                            $missing[] = $fieldName . '_' . $subField;
                        }
                    } else {
                        $missing[] = $fieldName;
                    }
                }
            }

            return $missing;
        }

        // Second priority: Check if expected format has 'fields' key (from initializeAI)
        if (isset($expectedFormat['fields'])) {
            foreach ($expectedFormat['fields'] as $fieldName => $fieldConfig) {
                // Skip optional fields
                if (!($fieldConfig['required'] ?? false)) {
                    continue;
                }

                // Check if field is satisfied
                $fieldSatisfied = $this->isFieldSatisfied($fieldName, $fieldConfig, $params);

                if (!$fieldSatisfied) {
                    $missing[] = $fieldName;
                }
            }
        }
        // Fallback to old format (required/optional arrays)
        elseif (isset($expectedFormat['required'])) {
            foreach ($expectedFormat['required'] as $fieldName) {
                if (empty($params[$fieldName])) {
                    $missing[] = $fieldName;
                }
            }
        }

        return $missing;
    }


    /**
     * Get missing critical business fields for models with conversational guidance
     * This checks for fields that are critical for business logic even if not marked required
     */
    protected function getMissingCriticalFields(array $params, string $modelClass): array
    {
        $missing = [];

        // Check model's AI config for required fields
        try {
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);

                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    if (!$method->isStatic()) {
                        $model = new $modelClass();
                        $config = $model->initializeAI();
                    } else {
                        $config = $modelClass::initializeAI();
                    }

                    $fields = $config['fields'] ?? [];

                    // Check each required field
                    foreach ($fields as $fieldName => $fieldConfig) {
                        if (!($fieldConfig['required'] ?? false)) {
                            continue;
                        }

                        // Check if field is satisfied
                        $fieldSatisfied = $this->isFieldSatisfied($fieldName, $fieldConfig, $params);

                        if (!$fieldSatisfied) {
                            $missing[] = $fieldName;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to get model critical fields', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }

        return $missing;
    }


    /**
     * Check if a field is satisfied based on extracted params
     * Handles complex field types like arrays, relationships, and composite fields
     * Generic implementation using field configuration
     */
    protected function isFieldSatisfied(string $fieldName, array $fieldConfig, array $params): bool
    {
        $fieldType = $fieldConfig['type'] ?? 'string';

        // Direct field match
        if (!empty($params[$fieldName])) {
            return true;
        }

        // Check alternative field names if specified in config
        // Alternative fields must ALL be present (they work together)
        // The model's initializeAI() should define which fields are equivalent
        if (isset($fieldConfig['alternative_fields'])) {
            $altFields = $fieldConfig['alternative_fields'];
            $hasAllAlternatives = true;
            
            foreach ($altFields as $altField) {
                // Check if this alternative field (or any of its configured aliases) is present
                if (empty($params[$altField])) {
                    $hasAllAlternatives = false;
                    break;
                }
            }
            
            if ($hasAllAlternatives) {
                return true;
            }
        }

        // For array/collection fields, check various patterns
        if ($fieldType === 'array') {
            // 1. Check for numbered pattern: {fieldName}_1_*, {fieldName}_2_*
            $singularName = rtrim($fieldName, 's'); // items -> item
            $numberedItems = $this->extractNumberedItems($params, $singularName);
            
            if (!empty($numberedItems)) {
                // Check if we have at least one complete item based on required sub-fields
                $requiredSubFields = $fieldConfig['fields'] ?? [];
                if ($this->hasCompleteNumberedItem($numberedItems, $requiredSubFields)) {
                    return true;
                }
            }

            // 2. Check for direct array field (the AI should extract to the correct field name)
            // No alias checking - the model's field descriptions should guide AI extraction
            if (!empty($params[$fieldName]) && is_array($params[$fieldName])) {
                if ($this->hasCompleteArrayItems($params[$fieldName], $fieldConfig)) {
                    return true;
                }
            }

            // 3. Check if we have flat fields that can form a single item
            if ($this->hasFlatFieldsForArray($params, $fieldConfig)) {
                return true;
            }
        }

        // For relationship fields, check for nested object or flat fields
        if ($fieldType === 'relationship') {
            // Check for nested object
            if (!empty($params[$fieldName]) && is_array($params[$fieldName])) {
                return true;
            }

            // Check for flat fields with prefix (e.g., customer_name, customer_email)
            $prefix = $fieldName . '_';
            $requiredSubFields = $fieldConfig['fields'] ?? [];
            
            if ($this->hasFlatRelationshipFields($params, $prefix, $requiredSubFields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract numbered items from params (e.g., item_1_name, item_2_price)
     */
    protected function extractNumberedItems(array $params, string $baseName): array
    {
        $numberedItems = [];
        $pattern = '/^' . preg_quote($baseName, '/') . '_(\d+)_(.+)$/';
        
        foreach ($params as $key => $value) {
            if (preg_match($pattern, $key, $matches)) {
                $itemNumber = $matches[1];
                $fieldName = $matches[2];
                
                if (!isset($numberedItems[$itemNumber])) {
                    $numberedItems[$itemNumber] = [];
                }
                $numberedItems[$itemNumber][$fieldName] = $value;
            }
        }
        
        return $numberedItems;
    }

    /**
     * Check if numbered items have at least one complete item
     */
    protected function hasCompleteNumberedItem(array $numberedItems, array $requiredSubFields): bool
    {
        foreach ($numberedItems as $itemData) {
            // If no required fields specified, just check if item has any data
            if (empty($requiredSubFields)) {
                if (!empty($itemData)) {
                    return true;
                }
                continue;
            }

            // Check if item has all required sub-fields
            $hasAllRequired = true;
            foreach ($requiredSubFields as $subField) {
                if (empty($itemData[$subField])) {
                    $hasAllRequired = false;
                    break;
                }
            }

            if ($hasAllRequired) {
                return true;
            }
        }

        return false;
    }


    /**
     * Check if array has complete items based on field config
     */
    protected function hasCompleteArrayItems(array $arrayData, array $fieldConfig): bool
    {
        $requiredSubFields = $fieldConfig['fields'] ?? [];
        
        foreach ($arrayData as $item) {
            if (!is_array($item)) {
                continue;
            }

            // If no required fields specified, any non-empty array item is valid
            if (empty($requiredSubFields)) {
                if (!empty($item)) {
                    return true;
                }
                continue;
            }

            // Check if item has required sub-fields
            $hasRequired = false;
            foreach ($requiredSubFields as $subField) {
                if (!empty($item[$subField])) {
                    $hasRequired = true;
                    break;
                }
            }

            if ($hasRequired) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if params have flat fields that can form an array item
     */
    protected function hasFlatFieldsForArray(array $params, array $fieldConfig): bool
    {
        $requiredSubFields = $fieldConfig['fields'] ?? [];
        
        if (empty($requiredSubFields)) {
            return false;
        }

        // Check if we have at least one required sub-field as a flat field
        foreach ($requiredSubFields as $subField) {
            if (!empty($params[$subField])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if params have flat relationship fields with prefix
     */
    protected function hasFlatRelationshipFields(array $params, string $prefix, array $requiredSubFields): bool
    {
        $foundFields = [];
        
        foreach ($params as $key => $value) {
            if (str_starts_with($key, $prefix) && !empty($value)) {
                $subField = substr($key, strlen($prefix));
                $foundFields[] = $subField;
            }
        }

        // If no required fields specified, any prefixed field is valid
        if (empty($requiredSubFields)) {
            return !empty($foundFields);
        }

        // Check if we have at least one required sub-field
        foreach ($requiredSubFields as $required) {
            if (in_array($required, $foundFields)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get missing required fields for a model action
     */
    protected function getMissingRequiredFields(array $params, array $definition, string $modelClass): array
    {
        $required = $definition['required_params'] ?? [];
        $missing = [];

        foreach ($required as $field) {
            if (empty($params[$field])) {
                $missing[] = $field;
            }
        }

        // Also check model's AI config for required fields
        try {
            if (class_exists($modelClass)) {
                $reflection = new \ReflectionClass($modelClass);

                if ($reflection->hasMethod('initializeAI')) {
                    $method = $reflection->getMethod('initializeAI');
                    if ($method->isStatic()) {
                        $config = $modelClass::initializeAI();
                        $fields = $config['fields'] ?? [];

                        foreach ($fields as $fieldName => $fieldConfig) {
                            if (($fieldConfig['required'] ?? false) && empty($params[$fieldName])) {
                                if (!in_array($fieldName, $missing)) {
                                    $missing[] = $fieldName;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Failed to get model required fields', [
                'model' => $modelClass,
                'error' => $e->getMessage()
            ]);
        }

        return $missing;
    }

    /**
     * Generate a draft reply using AI
     */
    protected function generateDraftReply(string $originalContent): string
    {
        if (!$this->aiService) {
            return '';
        }

        $prompt = "Generate a brief, professional reply to this email. Keep it concise:\n\n" . $originalContent;

        try {
            $response = $this->aiService->generate(
                new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    parameters: ['max_tokens' => 200]
                )
            );

            return $response->getContent();
        } catch (\Exception $e) {
            Log::warning('Failed to generate draft reply: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Get all registered action definitions
     */
    public function getActionDefinitions(): array
    {
        return $this->actionDefinitions;
    }

    /**
     * Get a specific action definition
     */
    public function getActionDefinition(string $id): ?array
    {
        return $this->actionDefinitions[$id] ?? null;
    }
}
