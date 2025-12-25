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

                // Check if model exists locally
                if (class_exists($modelClass)) {
                    $reflection = new \ReflectionClass($modelClass);
                    
                    // Check for executeAI method locally
                    if (!$reflection->hasMethod('executeAI')) {
                        continue;
                    }
                    
                    $hasExecuteAI = true;
                    $expectedFormat = $this->getModelExpectedFormat($modelClass);
                } 
                // Check if model has executeAI on remote node
                elseif (isset($remoteModelsInfo[$modelClass])) {
                    $modelInfo = $remoteModelsInfo[$modelClass];
                    
                    if (in_array('executeAI', $modelInfo['methods'] ?? [])) {
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
                    'model_class' => $modelClass,
                    'extractor' => function ($content, $sources, $metadata) use ($modelClass, $expectedFormat) {
                        return $this->extractModelParams($modelClass, $content, $sources, $metadata, $expectedFormat);
                    },
                    'executor' => 'model.dynamic',
                ]);

                Log::channel('ai-engine')->info('Registered dynamic model action', [
                    'model' => $modelClass,
                    'action_id' => $actionId,
                    'required_params' => $expectedFormat['required'] ?? [],
                    'is_remote' => !class_exists($modelClass),
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
    }

    /**
     * Get default format for models without initializeAI
     */
    protected function getDefaultFormat(): array
    {
        return [
            'required' => ['name'],
            'optional' => [],
        ];
    }

    /**
     * Get expected format from model (initializeAI or fillable)
     */
    protected function getModelExpectedFormat(string $modelClass): array
    {
        try {
            $reflection = new \ReflectionClass($modelClass);

            // Check for initializeAI method
            if ($reflection->hasMethod('initializeAI')) {
                $method = $reflection->getMethod('initializeAI');
                if ($method->isStatic()) {
                    return $modelClass::initializeAI();
                }
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
        
        // Add common variations for known model types
        if (str_contains($lower, 'product')) {
            $triggers = array_merge($triggers, [
                'product',
                'sell',
                'item',
                'goods',
                'merchandise',
            ]);
        }
        
        if (str_contains($lower, 'order')) {
            $triggers = array_merge($triggers, ['order', 'purchase', 'buy']);
        }
        
        if (str_contains($lower, 'invoice')) {
            $triggers = array_merge($triggers, ['invoice', 'bill', 'payment']);
        }
        
        if (str_contains($lower, 'customer') || str_contains($lower, 'client')) {
            $triggers = array_merge($triggers, ['customer', 'client', 'contact']);
        }
        
        return array_unique($triggers);
    }

    /**
     * Extract parameters for any model dynamically
     */
    protected function extractModelParams(string $modelClass, string $content, array $sources, array $metadata, array $expectedFormat): array
    {
        $allFields = array_merge(
            $expectedFormat['required'] ?? [],
            $expectedFormat['optional'] ?? []
        );

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

        // Use AI to extract structured data
        $prompt = "Extract the following fields from the conversation:\n";
        $prompt .= "Fields: " . implode(', ', $allFields) . "\n\n";
        $prompt .= "Conversation:\n{$context}\n\n";
        $prompt .= "Return ONLY a JSON object with the extracted values. Use null for missing fields.\n";
        $prompt .= "For relationship fields (ending in _id), extract the NAME not the ID.\n";
        $prompt .= "Example: {\"name\": \"value\", \"price\": 99.99, \"category\": \"Electronics\"}\n";

        try {
            $aiRequest = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                systemPrompt: 'You are a data extraction assistant. Extract structured data from conversations.',
                maxTokens: 500
            );
            
            $response = $this->aiService->generate($aiRequest);

            $extracted = json_decode($response->getContent(), true);
            
            if (is_array($extracted)) {
                // Filter out null values
                $extracted = array_filter($extracted, fn($v) => $v !== null);
                
                // Resolve relationship fields intelligently
                $extracted = $this->resolveRelationships($modelClass, $extracted, $userId);
                
                return $extracted;
            }
        } catch (\Exception $e) {
            Log::warning('AI extraction failed, using regex fallback: ' . $e->getMessage());
        }

        // Fallback to basic regex extraction
        return $this->regexExtractFields($content, $allFields);
    }

    /**
     * Resolve relationship fields intelligently
     */
    protected function resolveRelationships(string $modelClass, array $data, $userId = null): array
    {
        try {
            $model = new $modelClass();
            
            foreach ($data as $key => $value) {
                // Check if this is a relationship field (ends with _id but value is not numeric)
                if (str_ends_with($key, '_id') && !is_numeric($value)) {
                    // Get the relationship name (remove _id suffix)
                    $relationName = substr($key, 0, -3);
                    
                    // Check if model has this relationship
                    if (method_exists($model, $relationName)) {
                        $relation = $model->$relationName();
                        $relatedClass = get_class($relation->getRelated());
                        
                        Log::channel('ai-engine')->info('Resolving relationship', [
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
                
                // Search in name field (most common)
                $record = $query->where('name', 'LIKE', "%{$searchValue}%")->first();
                
                if ($record) {
                    Log::channel('ai-engine')->info('Found via SQL search', [
                        'id' => $record->id
                    ]);
                    return $record->id;
                }
            }
            
            // Not found - try to create if model allows it
            if (method_exists($relatedClass, 'createFromAI')) {
                Log::channel('ai-engine')->info('Creating new related record', [
                    'class' => $relatedClass,
                    'name' => $searchValue
                ]);
                
                $data = ['name' => $searchValue];
                if ($userId) {
                    $model = new $relatedClass();
                    if (in_array('user_id', $model->getFillable())) {
                        $data['user_id'] = $userId;
                    }
                }
                
                $newRecord = $relatedClass::createFromAI($data);
                return $newRecord->id ?? $newRecord['id'] ?? null;
            }
            
            // Try simple create as last resort
            $model = new $relatedClass();
            if (in_array('name', $model->getFillable())) {
                $data = ['name' => $searchValue];
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
     */
    protected function regexExtractFields(string $content, array $fields): array
    {
        $extracted = [];

        // Common patterns
        if (in_array('name', $fields)) {
            if (preg_match('/(?:sell|create|add)\s+([a-zA-Z0-9\s]+?)(?:\s+for|\s+at|\s+\$|$)/i', $content, $matches)) {
                $extracted['name'] = trim($matches[1]);
            }
        }

        if (in_array('price', $fields)) {
            if (preg_match('/\$?([\d,]+\.?\d*)/i', $content, $matches)) {
                $extracted['price'] = (float) str_replace(',', '', $matches[1]);
            }
        }

        if (in_array('description', $fields)) {
            if (preg_match('/(?:with|has|features?)\s+([^.]+)/i', $content, $matches)) {
                $extracted['description'] = trim($matches[1]);
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
        $actions = [];

        foreach ($this->actionDefinitions as $id => $definition) {
            // Check if any context triggers match
            if ($this->matchesTriggers($content, $definition['context_triggers'])) {
                // Extract parameters using the extractor
                $params = [];
                if (is_callable($definition['extractor'])) {
                    $params = call_user_func($definition['extractor'], $content, $sources, $metadata);
                }

                // Only add action if required params can be extracted
                if ($this->hasRequiredParams($params, $definition['required_params'])) {
                    $actions[] = new InteractiveAction(
                        id: $id . '_' . uniqid(),
                        type: ActionTypeEnum::from(ActionTypeEnum::BUTTON),
                        label: $definition['label'],
                        description: $definition['description'],
                        data: [
                            'action' => $id,
                            'executor' => $definition['executor'],
                            'model_class' => $definition['model_class'] ?? null,
                            'params' => $params,
                            'ready_to_execute' => true,
                        ]
                    );
                }
            }
        }

        return $actions;
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
            if (stripos($contentLower, strtolower($trigger)) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if all required params are present
     */
    protected function hasRequiredParams(array $params, array $required): bool
    {
        // If no required params, always return true
        if (empty($required)) {
            return true;
        }
        
        // For product-related models, be more lenient
        // Allow action if at least one key field is present
        $keyFields = ['name', 'title', 'subject', 'content'];
        $hasKeyField = false;
        
        foreach ($keyFields as $field) {
            if (isset($params[$field]) && !empty($params[$field])) {
                $hasKeyField = true;
                break;
            }
        }
        
        // If we have a key field, allow the action even if other required fields are missing
        // The executeAI method can handle auto-generation or defaults
        if ($hasKeyField) {
            return true;
        }
        
        // Otherwise, check all required params strictly
        foreach ($required as $param) {
            if (!isset($params[$param]) || empty($params[$param])) {
                return false;
            }
        }
        return true;
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
     * Extract product parameters from conversation using AI
     */
    protected function extractProductParams(string $content, array $sources, array $metadata): array
    {
        $params = [];

        // Extract product name
        if (preg_match('/(?:product|item|sell)\s+(?:called|named)?\s*["\']?([^"\'\n\.]+)["\']?/i', $content, $matches)) {
            $params['name'] = trim($matches[1]);
        } elseif (preg_match('/create\s+(?:a\s+)?product\s+["\']?([^"\'\n\.]+)["\']?/i', $content, $matches)) {
            $params['name'] = trim($matches[1]);
        }

        // Extract price
        if (preg_match('/(?:price|cost|sell\s+for|worth)\s*[:\$]?\s*(\d+(?:\.\d{2})?)/i', $content, $matches)) {
            $params['price'] = floatval($matches[1]);
        } elseif (preg_match('/\$(\d+(?:\.\d{2})?)/i', $content, $matches)) {
            $params['price'] = floatval($matches[1]);
        }

        // Extract description
        if (preg_match('/(?:description|about|details?)[:\s]+([^\n]+)/i', $content, $matches)) {
            $params['description'] = trim($matches[1]);
        }

        // Extract category
        if (preg_match('/(?:category|type)[:\s]+([^\n]+)/i', $content, $matches)) {
            $params['category'] = trim($matches[1]);
        }

        // Extract SKU
        if (preg_match('/(?:sku|code)[:\s]+([A-Z0-9\-]+)/i', $content, $matches)) {
            $params['sku'] = trim($matches[1]);
        }

        // Extract stock quantity
        if (preg_match('/(?:stock|quantity|qty)[:\s]+(\d+)/i', $content, $matches)) {
            $params['stock_quantity'] = intval($matches[1]);
        }

        // Extract status
        if (preg_match('/(?:status)[:\s]+(active|inactive|draft)/i', $content, $matches)) {
            $params['status'] = strtolower($matches[1]);
        }

        // Use AI to fill missing fields from conversation history
        if ($this->aiService && isset($metadata['conversation_history'])) {
            $params = $this->fillProductParamsWithAI($params, $content, $metadata['conversation_history']);
        }

        return $params;
    }

    /**
     * Use AI to fill missing product parameters from conversation history
     */
    protected function fillProductParamsWithAI(array $params, string $currentMessage, array $conversationHistory): array
    {
        if (!$this->aiService) {
            return $params;
        }

        // Build context from conversation history
        $context = "Conversation history:\n";
        foreach (array_slice($conversationHistory, -5) as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';
            $context .= "{$role}: {$content}\n";
        }
        $context .= "\nCurrent message: {$currentMessage}\n\n";

        // Identify missing required fields
        $missingFields = [];
        if (empty($params['name'])) $missingFields[] = 'name';
        if (empty($params['price'])) $missingFields[] = 'price';

        // Identify missing optional fields that could be filled
        $optionalFields = [];
        if (empty($params['description'])) $optionalFields[] = 'description';
        if (empty($params['category'])) $optionalFields[] = 'category';
        if (empty($params['sku'])) $optionalFields[] = 'sku';
        if (!isset($params['stock_quantity'])) $optionalFields[] = 'stock_quantity';
        if (empty($params['status'])) $optionalFields[] = 'status';

        if (empty($missingFields) && empty($optionalFields)) {
            return $params;
        }

        // Create AI prompt to extract missing fields
        $prompt = $context;
        $prompt .= "Extract product information from the conversation above. Return ONLY a JSON object with these fields:\n";
        
        if (!empty($missingFields)) {
            $prompt .= "Required fields: " . implode(', ', $missingFields) . "\n";
        }
        if (!empty($optionalFields)) {
            $prompt .= "Optional fields (only if mentioned): " . implode(', ', $optionalFields) . "\n";
        }

        $prompt .= "\nField descriptions:\n";
        $prompt .= "- name: Product name or title\n";
        $prompt .= "- price: Numeric price (e.g., 99.99)\n";
        $prompt .= "- description: Product description\n";
        $prompt .= "- category: Product category\n";
        $prompt .= "- sku: Stock Keeping Unit code\n";
        $prompt .= "- stock_quantity: Available quantity (integer)\n";
        $prompt .= "- status: active, inactive, or draft\n\n";
        $prompt .= "Return ONLY valid JSON, no other text.";

        try {
            $response = $this->aiService->generate(
                new \LaravelAIEngine\DTOs\AIRequest(
                    prompt: $prompt,
                    engine: \LaravelAIEngine\Enums\EngineEnum::from('openai'),
                    model: \LaravelAIEngine\Enums\EntityEnum::from('gpt-4o-mini'),
                    parameters: ['max_tokens' => 300, 'temperature' => 0.3]
                )
            );

            $aiContent = $response->getContent();
            
            // Extract JSON from response
            if (preg_match('/\{[^}]+\}/', $aiContent, $matches)) {
                $extracted = json_decode($matches[0], true);
                if (is_array($extracted)) {
                    // Merge AI-extracted params with existing params (existing takes precedence)
                    foreach ($extracted as $key => $value) {
                        if (!isset($params[$key]) || empty($params[$key])) {
                            $params[$key] = $value;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to extract product params with AI: ' . $e->getMessage());
        }

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
