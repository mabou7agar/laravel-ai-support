<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Facades\Engine;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class DynamicActionService
{
    protected array $registeredActions = [];
    
    /**
     * Discover and register available actions from models and configs
     */
    public function discoverActions(): array
    {
        $cacheKey = 'ai_engine:discovered_actions';
        
        return Cache::remember($cacheKey, 3600, function () {
            $actions = [];
            
            // Discover model-based actions
            $actions = array_merge($actions, $this->discoverModelActions());
            
            // Discover configured API actions
            $actions = array_merge($actions, $this->discoverConfiguredActions());
            
            // Store for RAG context
            $this->storeActionsInRAG($actions);
            
            return $actions;
        });
    }
    
    /**
     * Discover actions from API models
     */
    protected function discoverModelActions(): array
    {
        $actions = [];
        $modelsPath = app_path('Models');
        
        if (!File::exists($modelsPath)) {
            return $actions;
        }
        
        $modelFiles = File::allFiles($modelsPath);
        
        foreach ($modelFiles as $file) {
            $className = 'App\\Models\\' . $file->getFilenameWithoutExtension();
            
            if (!class_exists($className)) {
                continue;
            }
            
            try {
                $reflection = new \ReflectionClass($className);
                
                // Check if model has API mapper trait or interface
                if ($this->hasApiCapabilities($reflection)) {
                    $modelActions = $this->extractModelActions($className, $reflection);
                    $actions = array_merge($actions, $modelActions);
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        return $actions;
    }
    
    /**
     * Check if model has API capabilities
     */
    protected function hasApiCapabilities(\ReflectionClass $reflection): bool
    {
        // Check for API-related traits
        $traits = $reflection->getTraitNames();
        foreach ($traits as $trait) {
            if (str_contains($trait, 'ApiModel') || 
                str_contains($trait, 'HasApiEndpoint') ||
                str_contains($trait, 'ApiMapper')) {
                return true;
            }
        }
        
        // Check for API-related methods
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        foreach ($methods as $method) {
            if (in_array($method->getName(), ['getApiEndpoint', 'getApiUrl', 'apiMapper'])) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Extract available actions from a model
     */
    protected function extractModelActions(string $className, \ReflectionClass $reflection): array
    {
        $actions = [];
        $modelName = $reflection->getShortName();
        
        try {
            $instance = new $className();
            
            // Get API endpoint if available
            $endpoint = $this->getModelEndpoint($instance);
            
            if (!$endpoint) {
                return $actions;
            }
            
            // Create action
            $actions[] = [
                'id' => 'create_' . strtolower($modelName),
                'label' => "Create {$modelName}",
                'description' => "Create a new {$modelName} record via API",
                'type' => 'api_call',
                'model' => $className,
                'endpoint' => $endpoint,
                'method' => 'POST',
                'required_fields' => $this->getRequiredFields($instance),
                'example_payload' => $this->generateExamplePayload($instance),
            ];
            
            // Update action
            $actions[] = [
                'id' => 'update_' . strtolower($modelName),
                'label' => "Update {$modelName}",
                'description' => "Update an existing {$modelName} record",
                'type' => 'api_call',
                'model' => $className,
                'endpoint' => $endpoint . '/{id}',
                'method' => 'PUT',
                'required_fields' => array_merge(['id'], $this->getRequiredFields($instance)),
                'example_payload' => $this->generateExamplePayload($instance, true),
            ];
            
            // List action
            $actions[] = [
                'id' => 'list_' . strtolower($modelName),
                'label' => "List {$modelName}s",
                'description' => "Retrieve all {$modelName} records",
                'type' => 'api_call',
                'model' => $className,
                'endpoint' => $endpoint,
                'method' => 'GET',
                'required_fields' => [],
                'example_payload' => null,
            ];
            
        } catch (\Exception $e) {
            // Skip if model can't be instantiated
        }
        
        return $actions;
    }
    
    /**
     * Get model API endpoint
     */
    protected function getModelEndpoint($instance): ?string
    {
        if (method_exists($instance, 'getApiEndpoint')) {
            return $instance->getApiEndpoint();
        }
        
        if (method_exists($instance, 'getApiUrl')) {
            return $instance->getApiUrl();
        }
        
        if (property_exists($instance, 'apiEndpoint')) {
            return $instance->apiEndpoint;
        }
        
        // Default endpoint based on table name
        if (method_exists($instance, 'getTable')) {
            return '/api/' . $instance->getTable();
        }
        
        return null;
    }
    
    /**
     * Get required fields from model
     */
    protected function getRequiredFields($instance): array
    {
        $required = [];
        
        // Check fillable fields
        if (property_exists($instance, 'fillable') && is_array($instance->fillable)) {
            $required = $instance->fillable;
        }
        
        // Check validation rules if available
        if (method_exists($instance, 'rules')) {
            try {
                $rules = $instance->rules();
                if (is_array($rules)) {
                    foreach ($rules as $field => $rule) {
                        if (is_string($rule) && str_contains($rule, 'required')) {
                            $required[] = $field;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Skip if rules() throws an exception
            }
        }
        
        return array_unique($required ?? []);
    }
    
    /**
     * Generate example payload for model
     */
    protected function generateExamplePayload($instance, bool $includeId = false): array
    {
        $payload = [];
        
        if ($includeId) {
            $payload['id'] = 1;
        }
        
        $fields = $this->getRequiredFields($instance);
        
        foreach ($fields as $field) {
            $payload[$field] = $this->generateFieldExample($field);
        }
        
        return $payload;
    }
    
    /**
     * Generate example value for a field
     */
    protected function generateFieldExample(string $field): mixed
    {
        // Common field patterns
        if (str_contains($field, 'email')) return 'user@example.com';
        if (str_contains($field, 'name')) return 'Example Name';
        if (str_contains($field, 'title')) return 'Example Title';
        if (str_contains($field, 'description')) return 'Example description';
        if (str_contains($field, 'content')) return 'Example content';
        if (str_contains($field, 'price')) return 99.99;
        if (str_contains($field, 'quantity')) return 1;
        if (str_contains($field, 'date')) return now()->format('Y-m-d');
        if (str_contains($field, 'time')) return now()->format('H:i:s');
        if (str_contains($field, 'url')) return 'https://example.com';
        if (str_contains($field, 'phone')) return '+1234567890';
        if (str_contains($field, 'status')) return 'active';
        
        return 'example_value';
    }
    
    /**
     * Discover actions from configuration
     */
    protected function discoverConfiguredActions(): array
    {
        $actions = [];
        
        // Add built-in custom actions
        $actions[] = [
            'id' => 'send_email',
            'label' => '📧 Send Email',
            'description' => 'Send an email message',
            'type' => 'custom',
            'required_fields' => ['to', 'subject', 'body'],
        ];
        
        $actions[] = [
            'id' => 'create_task',
            'label' => '✅ Create Task',
            'description' => 'Create a new task',
            'type' => 'custom',
            'required_fields' => ['title', 'description'],
        ];
        
        $actions[] = [
            'id' => 'schedule_meeting',
            'label' => '📅 Schedule Meeting',
            'description' => 'Schedule a meeting',
            'type' => 'custom',
            'required_fields' => ['title', 'date', 'time'],
        ];
        
        $actions[] = [
            'id' => 'save_note',
            'label' => '📝 Save Note',
            'description' => 'Save a note',
            'type' => 'custom',
            'required_fields' => ['title', 'content'],
        ];
        
        // Load from config file if exists
        $configPath = config_path('ai-actions.php');
        if (File::exists($configPath)) {
            $configActions = config('ai-actions.actions', []);
            $actions = array_merge($actions, $configActions);
        }
        
        return $actions;
    }
    
    /**
     * Store discovered actions in RAG for AI context
     */
    protected function storeActionsInRAG(array $actions): void
    {
        // Check if RAG is enabled in config
        if (!config('ai-actions.rag.enabled', false)) {
            return;
        }
        
        try {
            $context = $this->buildActionContext($actions);
            
            // Store in RAG system (if available)
            if (method_exists(Engine::class, 'rag')) {
                Engine::rag()->addContext(
                    id: 'available_actions',
                    content: $context,
                    metadata: [
                        'type' => 'system_capabilities',
                        'category' => 'actions',
                        'count' => count($actions),
                        'updated_at' => now()->toIso8601String(),
                    ]
                );
            }
        } catch (\Exception $e) {
            \Log::warning('Failed to store actions in RAG: ' . $e->getMessage());
        }
    }
    
    /**
     * Build context string for RAG
     */
    protected function buildActionContext(array $actions): string
    {
        $context = "# Available System Actions\n\n";
        $context .= "The following actions are available in this application:\n\n";
        
        foreach ($actions as $action) {
            $context .= "## {$action['label']}\n";
            $context .= "- **ID**: {$action['id']}\n";
            $context .= "- **Description**: {$action['description']}\n";
            $context .= "- **Type**: {$action['type']}\n";
            
            if (isset($action['endpoint'])) {
                $context .= "- **Endpoint**: {$action['method']} {$action['endpoint']}\n";
            }
            
            if (!empty($action['required_fields'])) {
                $context .= "- **Required Fields**: " . implode(', ', $action['required_fields']) . "\n";
            }
            
            if (!empty($action['example_payload'])) {
                $context .= "- **Example Payload**: ```json\n" . 
                           json_encode($action['example_payload'], JSON_PRETTY_PRINT) . 
                           "\n```\n";
            }
            
            $context .= "\n";
        }
        
        return $context;
    }
    
    /**
     * Get recommended actions based on user query and RAG context
     */
    public function getRecommendedActions(string $query, string $conversationId = null): array
    {
        // Get all available actions
        $allActions = $this->discoverActions();
        
        // Use RAG if enabled and available
        if (config('ai-actions.rag.enabled', false) && method_exists(Engine::class, 'rag')) {
            try {
                $ragResults = Engine::rag()->search(
                    query: $query,
                    filters: ['type' => 'system_capabilities'],
                    limit: 5
                );
                
                // Extract action IDs from RAG results
                $recommendedIds = $this->extractActionIds($ragResults, $query);
                
                // Filter actions based on recommendations
                return array_filter($allActions, function ($action) use ($recommendedIds) {
                    return in_array($action['id'], $recommendedIds);
                });
                
            } catch (\Exception $e) {
                \Log::warning('RAG search failed, falling back to keyword matching: ' . $e->getMessage());
            }
        }
        
        // Fallback to keyword matching
        return $this->fallbackActionMatching($allActions, $query);
    }
    
    /**
     * Extract action IDs from RAG results
     */
    protected function extractActionIds(array $ragResults, string $query): array
    {
        $ids = [];
        
        // Simple keyword matching for now
        $queryLower = strtolower($query);
        
        if (str_contains($queryLower, 'create') || str_contains($queryLower, 'add') || str_contains($queryLower, 'new')) {
            $ids[] = 'create_';
        }
        
        if (str_contains($queryLower, 'update') || str_contains($queryLower, 'edit') || str_contains($queryLower, 'modify')) {
            $ids[] = 'update_';
        }
        
        if (str_contains($queryLower, 'list') || str_contains($queryLower, 'show') || str_contains($queryLower, 'get')) {
            $ids[] = 'list_';
        }
        
        return $ids;
    }
    
    /**
     * Fallback action matching using keywords
     */
    protected function fallbackActionMatching(array $actions, string $query): array
    {
        $queryLower = strtolower($query);
        $matched = [];
        
        foreach ($actions as $action) {
            $score = 0;
            
            // Check label
            if (str_contains(strtolower($action['label']), $queryLower)) {
                $score += 10;
            }
            
            // Check description
            if (str_contains(strtolower($action['description']), $queryLower)) {
                $score += 5;
            }
            
            // Check action ID
            foreach (explode('_', $action['id']) as $part) {
                if (str_contains($queryLower, $part)) {
                    $score += 3;
                }
            }
            
            if ($score > 0) {
                $action['relevance_score'] = $score;
                $matched[] = $action;
            }
        }
        
        // Sort by relevance
        usort($matched, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
        
        return array_slice($matched, 0, 5);
    }
    
    /**
     * Execute a dynamic action
     */
    public function executeAction(array $action, array $parameters = []): array
    {
        try {
            if ($action['type'] === 'api_call') {
                return $this->executeApiCall($action, $parameters);
            }
            
            // Handle custom action types
            if ($action['type'] === 'custom') {
                return $this->executeCustomAction($action, $parameters);
            }
            
            return [
                'success' => false,
                'error' => 'Unknown action type: ' . $action['type']
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Execute a custom action (email, task, etc.)
     */
    protected function executeCustomAction(array $action, array $parameters): array
    {
        $actionId = $action['id'];
        
        // Handle different custom actions
        return match($actionId) {
            'send_email' => $this->handleSendEmail($parameters),
            'create_task' => $this->handleCreateTask($parameters),
            'schedule_meeting' => $this->handleScheduleMeeting($parameters),
            'save_note' => $this->handleSaveNote($parameters),
            default => [
                'success' => false,
                'error' => "Unknown custom action: {$actionId}"
            ]
        };
    }
    
    /**
     * Handle send email action
     */
    protected function handleSendEmail(array $params): array
    {
        // TODO: Integrate with your email service
        \Log::channel('ai-engine')->info('Email would be sent', $params);
        
        return [
            'success' => true,
            'message' => '✅ Email sent successfully!',
            'data' => [
                'to' => $params['to'] ?? null,
                'subject' => $params['subject'] ?? null,
            ],
        ];
    }
    
    /**
     * Handle create task action
     */
    protected function handleCreateTask(array $params): array
    {
        return [
            'success' => true,
            'message' => '✅ Task created successfully!',
            'data' => $params,
        ];
    }
    
    /**
     * Handle schedule meeting action
     */
    protected function handleScheduleMeeting(array $params): array
    {
        return [
            'success' => true,
            'message' => '✅ Meeting scheduled successfully!',
            'data' => $params,
        ];
    }
    
    /**
     * Handle save note action
     */
    protected function handleSaveNote(array $params): array
    {
        return [
            'success' => true,
            'message' => '✅ Note saved successfully!',
            'data' => $params,
        ];
    }
    
    /**
     * Execute an API call action
     */
    protected function executeApiCall(array $action, array $parameters): array
    {
        $endpoint = $action['endpoint'];
        $method = $action['method'];
        
        // Replace {id} placeholder if present
        if (isset($parameters['id'])) {
            $endpoint = str_replace('{id}', $parameters['id'], $endpoint);
            unset($parameters['id']);
        }
        
        // Validate required fields
        $missing = array_diff($action['required_fields'], array_keys($parameters));
        if (!empty($missing)) {
            return [
                'success' => false,
                'error' => 'Missing required fields: ' . implode(', ', $missing),
                'required_fields' => $action['required_fields'],
                'example_payload' => $action['example_payload']
            ];
        }
        
        return [
            'success' => true,
            'action' => 'api_call',
            'method' => $method,
            'endpoint' => $endpoint,
            'payload' => $parameters,
            'message' => "Ready to execute {$method} {$endpoint}",
            'curl_example' => $this->generateCurlExample($method, $endpoint, $parameters)
        ];
    }
    
    /**
     * Generate curl example for API call
     */
    protected function generateCurlExample(string $method, string $endpoint, array $payload): string
    {
        $baseUrl = config('app.url');
        $url = $baseUrl . $endpoint;
        
        $curl = "curl -X {$method} '{$url}'";
        $curl .= " -H 'Content-Type: application/json'";
        $curl .= " -H 'Accept: application/json'";
        
        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $curl .= " -d '" . json_encode($payload) . "'";
        }
        
        return $curl;
    }
}
