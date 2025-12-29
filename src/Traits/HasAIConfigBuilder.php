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
            'actions' => ['create', 'update', 'delete'],
            'fields' => [],
        ];
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
        array $options = []
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
     * Build the configuration array
     */
    public function build(): array
    {
        return $this->config;
    }
}
