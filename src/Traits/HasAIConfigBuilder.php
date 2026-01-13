<?php

namespace LaravelAIEngine\Traits;

/**
 * Fluent AI Configuration Builder
 * 
 * Provides a fluent interface for building AI configurations with minimal code.
 * 
 * Example:
 * public function initializeAI(): array
 * {
 *     return $this->aiConfig()
 *         ->description('Customer invoice with line items')
 *         ->field('name', 'Customer full name', required: true)
 *         ->arrayField('items', 'Invoice line items', [
 *             'item' => 'Product name',
 *             'price' => 'Unit price',
 *             'quantity' => 'Quantity (default: 1)',
 *         ])
 *         ->build();
 * }
 */
trait HasAIConfigBuilder
{
    protected ?AIConfigBuilder $configBuilder = null;
    
    /**
     * Start building AI configuration
     */
    protected function aiConfig(): AIConfigBuilder
    {
        if (!$this->configBuilder) {
            $this->configBuilder = new AIConfigBuilder($this);
        }
        return $this->configBuilder;
    }
}

class AIConfigBuilder
{
    protected array $config = [];
    protected $model;
    
    public function __construct($model)
    {
        $this->model = $model;
        $this->config = [
            'model_name' => class_basename($model),
            'description' => '',
            'goal' => '',
            'actions' => ['create', 'update', 'delete'],
            'fields' => [],
        ];
    }
    
    /**
     * Set model goal (primary objective)
     */
    public function goal(string $goal): self
    {
        $this->config['goal'] = $goal;
        return $this;
    }
    
    /**
     * Set model description
     */
    public function description(string $description): self
    {
        $this->config['description'] = $description;
        return $this;
    }
    
    /**
     * Set supported actions
     */
    public function actions(array $actions): self
    {
        $this->config['actions'] = $actions;
        return $this;
    }
    
    /**
     * Add a simple field
     */
    public function field(
        string $name,
        string $description,
        string $type = 'string',
        bool $required = false,
        mixed $default = null,
        array $options = [],
        ?string $prompt = null,
        ?string $validation = null
    ): self {
        $fieldConfig = [
            'type' => $type,
            'description' => $description,
            'required' => $required,
        ];
        
        if ($default !== null) {
            $fieldConfig['default'] = $default;
        }
        
        if (!empty($options)) {
            $fieldConfig['options'] = $options;
        }
        
        if ($prompt !== null) {
            $fieldConfig['prompt'] = $prompt;
        }
        
        if ($validation !== null) {
            $fieldConfig['validation'] = $validation;
        }
        
        $this->config['fields'][$name] = $fieldConfig;
        return $this;
    }
    
    /**
     * Add an array field with structure
     */
    public function arrayField(
        string $name,
        string $description,
        array $itemStructure,
        bool $required = false,
        int $minItems = 0
    ): self {
        $fieldConfig = [
            'type' => 'array',
            'description' => $description,
            'required' => $required,
        ];
        
        if ($minItems > 0) {
            $fieldConfig['min_items'] = $minItems;
        }
        
        // Convert simple structure to full structure
        $fullStructure = [];
        foreach ($itemStructure as $key => $desc) {
            if (is_string($desc)) {
                $fullStructure[$key] = [
                    'type' => $this->inferType($key),
                    'description' => $desc,
                    'required' => true,
                ];
            } else {
                $fullStructure[$key] = $desc;
            }
        }
        
        $fieldConfig['item_structure'] = $fullStructure;
        $this->config['fields'][$name] = $fieldConfig;
        return $this;
    }
    
    /**
     * Add a relationship field with auto-resolution
     */
    public function relationship(
        string $name,
        string $description,
        string $modelClass,
        string $searchField = 'name',
        bool $required = false,
        bool $createIfMissing = false,
        array $defaults = []
    ): self {
        $this->config['fields'][$name] = [
            'type' => 'relationship',
            'description' => $description,
            'required' => $required,
            'relationship' => [
                'model' => $modelClass,
                'search_field' => $searchField,
                'create_if_missing' => $createIfMissing,
                'defaults' => $defaults,
            ],
        ];
        return $this;
    }
    
    /**
     * Add auto-resolving relationship (creates if not found)
     */
    public function autoRelationship(
        string $name,
        string $description,
        string $modelClass,
        string $searchField = 'name',
        array $defaults = []
    ): self {
        return $this->relationship(
            $name,
            $description,
            $modelClass,
            $searchField,
            required: false,
            createIfMissing: true,
            defaults: $defaults
        );
    }
    
    /**
     * Add an enum field
     */
    public function enum(
        string $name,
        string $description,
        array $options,
        mixed $default = null,
        bool $required = false
    ): self {
        return $this->field($name, $description, 'enum', $required, $default, $options);
    }
    
    /**
     * Add a date field
     */
    public function date(
        string $name,
        string $description,
        mixed $default = null,
        bool $required = false
    ): self {
        return $this->field($name, $description, 'date', $required, $default);
    }
    
    /**
     * Add conversational guidance for AI
     * 
     * Provides instructions to the AI on how to interact with users
     * when collecting data for this model. The AI will use these
     * guidelines to ask for missing information progressively.
     * 
     * If not provided, default guidance is generated based on entity fields.
     * 
     * @param array|string $guidance Array of guidance strings or single string
     * @return self
     */
    public function conversationalGuidance(array|string $guidance): self
    {
        if (is_string($guidance)) {
            $guidance = [$guidance];
        }
        
        // Store guidance separately (will be added to description in build())
        $this->config['conversational_guidance'] = $guidance;
        
        return $this;
    }
    
    /**
     * Generate default conversational guidance based on entity fields
     */
    protected function generateDefaultGuidance(): array
    {
        $guidance = [];
        $modelName = $this->config['model_name'] ?? 'Record';
        
        // Find entity fields
        $entityFields = [];
        foreach ($this->config['fields'] as $fieldName => $fieldConfig) {
            if (in_array($fieldConfig['type'] ?? '', ['entity', 'entities'])) {
                $entityFields[$fieldName] = $fieldConfig;
            }
        }
        
        if (!empty($entityFields)) {
            $guidance[] = "{$modelName} creation uses automatic entity resolution:";
            $guidance[] = "";
            
            foreach ($entityFields as $fieldName => $fieldConfig) {
                $entityName = class_basename($fieldConfig['model'] ?? 'Entity');
                $isMultiple = ($fieldConfig['type'] ?? '') === 'entities';
                
                if ($isMultiple) {
                    $guidance[] = "• {$entityName}s: Searches by " . implode(', ', $fieldConfig['search_fields'] ?? ['name']);
                } else {
                    $guidance[] = "• {$entityName}: Searches by " . implode(', ', $fieldConfig['search_fields'] ?? ['name']);
                }
                
                if ($fieldConfig['interactive'] ?? true) {
                    $guidance[] = "  → If not found, asks user to create interactively";
                } else {
                    $guidance[] = "  → If not found, creates automatically";
                }
            }
            
            $guidance[] = "";
            $guidance[] = "User can type 'cancel' at any time to abort.";
        }
        
        return $guidance;
    }
    
    /**
     * Define critical fields that must be satisfied before action execution
     * 
     * Critical fields are checked using smart validation that understands:
     * - Relationship fields (customer, user, etc.)
     * - Array/collection fields (items, products, etc.)
     * - Composite data (name + price = valid item)
     * 
     * @param array $criticalFields Array of field names or field definitions
     * @return self
     * 
     * @example
     * ->criticalFields(['customer', 'items'])
     * ->criticalFields([
     *     'customer' => ['type' => 'relationship', 'fields' => ['name', 'email']],
     *     'items' => ['type' => 'array']
     * ])
     */
    public function criticalFields(array $criticalFields): self
    {
        $this->config['critical_fields'] = $criticalFields;
        
        return $this;
    }
    
    /**
     * Add extraction format hints
     */
    public function extractionHints(array $hints): self
    {
        $this->config['extraction_format'] = $hints;
        return $this;
    }
    
    /**
     * Add examples
     */
    public function examples(string $fieldName, array $examples): self
    {
        if (isset($this->config['fields'][$fieldName])) {
            $this->config['fields'][$fieldName]['examples'] = $examples;
        }
        return $this;
    }
    
    /**
     * Add an entity field (relationship with auto-resolution)
     * 
     * Defines a field that references another model with automatic
     * find-or-create capability driven by GenericEntityResolver.
     * 
     * @param string $name Field name (e.g., 'customer_id')
     * @param array|\LaravelAIEngine\DTOs\EntityFieldConfig $config Entity configuration
     * @return self
     * 
     * @example
     * // Using array (legacy)
     * ->entityField('customer_id', [
     *     'model' => Customer::class,
     *     'search_fields' => ['name', 'email', 'contact'],
     *     'filters' => fn($query) => $query->where('workspace', getActiveWorkSpace()),
     *     'subflow' => CreateCustomerWorkflow::class,
     * ])
     * 
     * // Using DTO (recommended)
     * ->entityField('customer_id', EntityFieldConfig::make(Customer::class)
     *     ->searchFields(['name', 'email', 'contact'])
     *     ->filters(fn($query) => $query->where('workspace', getActiveWorkSpace()))
     *     ->subflow(CreateCustomerWorkflow::class)
     *     ->checkDuplicates()
     * )
     */
    public function entityField(string $name, array|\LaravelAIEngine\DTOs\EntityFieldConfig $config): self
    {
        // Convert DTO to array if needed
        if ($config instanceof \LaravelAIEngine\DTOs\EntityFieldConfig) {
            $config = $config->toArray();
        }
        
        $this->config['fields'][$name] = array_merge([
            'type' => 'entity',
            'required' => $config['required'] ?? false,
            'description' => $config['description'] ?? class_basename($config['model'] ?? '') . ' reference',
            'resolver' => $config['resolver'] ?? 'GenericEntityResolver',
        ], $config);
        
        // Store as entities config for workflow integration
        if (!isset($this->config['entities'])) {
            $this->config['entities'] = [];
        }
        $this->config['entities'][$name] = $config;
        
        return $this;
    }
    
    /**
     * Add an entities field (multiple relationships with auto-resolution)
     * 
     * Defines a field that references multiple instances of another model
     * with automatic find-or-create capability.
     * 
     * @param string $name Field name (e.g., 'products', 'items')
     * @param array|\LaravelAIEngine\DTOs\EntityFieldConfig $config Entity configuration
     * @return self
     * 
     * @example
     * // Using array (legacy)
     * ->entitiesField('products', [
     *     'model' => Product::class,
     *     'search_fields' => ['name', 'sku'],
     *     'filters' => fn($query) => $query->where('workspace_id', getActiveWorkSpace()),
     *     'subflow' => CreateProductWorkflow::class,
     * ])
     * 
     * // Using DTO (recommended)
     * ->entitiesField('items', EntityFieldConfig::make(Product::class)
     *     ->searchFields(['name', 'sku'])
     *     ->filters(fn($query) => $query->where('workspace_id', getActiveWorkSpace()))
     *     ->subflow(CreateProductWorkflow::class)
     *     ->multiple()
     * )
     */
    public function entitiesField(string $name, array|\LaravelAIEngine\DTOs\EntityFieldConfig $config): self
    {
        // Convert DTO to array if needed
        if ($config instanceof \LaravelAIEngine\DTOs\EntityFieldConfig) {
            $config = $config->toArray();
        }
        
        // Ensure multiple flag is set
        $config['multiple'] = true;
        
        $this->config['fields'][$name] = array_merge([
            'type' => 'entities',
            'required' => $config['required'] ?? false,
            'description' => $config['description'] ?? 'Multiple ' . class_basename($config['model'] ?? '') . ' references',
            'resolver' => $config['resolver'] ?? 'GenericEntityResolver',
        ], $config);
        
        // Store as entities config for workflow integration
        if (!isset($this->config['entities'])) {
            $this->config['entities'] = [];
        }
        $this->config['entities'][$name] = $config;
        
        return $this;
    }
    
    /**
     * Specify workflow class to use for this model
     */
    public function workflow(string $workflowClass): self
    {
        $this->config['workflow'] = $workflowClass;
        return $this;
    }
    
    /**
     * Infer type from field name
     */
    protected function inferType(string $name): string
    {
        if (str_contains($name, 'price') || str_contains($name, 'amount') || str_contains($name, 'total')) {
            return 'number';
        }
        if (str_contains($name, 'quantity') || str_contains($name, 'count')) {
            return 'integer';
        }
        if (str_contains($name, 'date')) {
            return 'date';
        }
        return 'string';
    }
    
    /**
     * Build and return the configuration
     */
    public function build(): array
    {
        // Add conversational guidance to description
        $guidance = $this->config['conversational_guidance'] ?? $this->generateDefaultGuidance();
        
        if (!empty($guidance)) {
            $guidanceText = "\n\nCONVERSATIONAL GUIDANCE:\n" . implode("\n", $guidance);
            $this->config['description'] .= $guidanceText;
        }
        
        return $this->config;
    }
}
