<?php

namespace LaravelAIEngine\DTOs;

class EntityFieldConfig
{
    public function __construct(
        public string $model,
        public array $searchFields = [],
        public ?\Closure $filters = null,
        public bool $checkDuplicates = false,
        public bool $askOnDuplicate = false,
        public ?string $subflow = null,
        public bool $multiple = false,
        public bool $required = true,
        public ?string $description = null,
        public ?string $prompt = null,
        public array $validation = [],
        public ?\Closure $identifierProvider = null,
        public bool $confirmBeforeCreate = false,
        public array $displayFields = [],
        public ?\Closure $creationPrompt = null,
        public ?string $friendlyName = null,
        public ?\Closure $nameExtractor = null,
        public ?\Closure $categoryInferrer = null,
        public ?\Closure $fieldInferrer = null,
        public ?string $parsingGuide = null,
        public array $includeFields = [],
        public array $baseFields = [],
        public array $requiredItemFields = [],
    ) {}

    /**
     * Create a single entity field configuration
     */
    public static function make(string $model): self
    {
        return new self(model: $model);
    }

    /**
     * Set search fields for entity resolution
     */
    public function searchFields(array $fields): self
    {
        $this->searchFields = $fields;
        return $this;
    }

    /**
     * Set query filters for entity resolution
     */
    public function filters(\Closure $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    /**
     * Enable duplicate checking
     */
    public function checkDuplicates(bool $askOnDuplicate = true): self
    {
        $this->checkDuplicates = true;
        $this->askOnDuplicate = $askOnDuplicate;
        return $this;
    }

    /**
     * Set subflow for entity creation
     */
    public function subflow(string $subflowClass): self
    {
        $this->subflow = $subflowClass;
        return $this;
    }

    /**
     * Mark as multiple entities (array)
     */
    public function multiple(bool $multiple = true): self
    {
        $this->multiple = $multiple;
        return $this;
    }

    /**
     * Set if field is required
     */
    public function required(bool $required = true): self
    {
        $this->required = $required;
        return $this;
    }

    /**
     * Set description
     */
    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * Set prompt for data collection
     */
    public function prompt(string $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Set validation rules
     */
    public function validation(array $rules): self
    {
        $this->validation = $rules;
        return $this;
    }

    /**
     * Set identifier provider - closure that generates identifier from context
     * Example: fn($context) => suggestCategoryFromProduct($context->get('collected_data')['product_name'])
     */
    public function identifierProvider(\Closure $provider): self
    {
        $this->identifierProvider = $provider;
        return $this;
    }

    /**
     * Require user confirmation before creating new entity
     */
    public function confirmBeforeCreate(bool $confirm = true): self
    {
        $this->confirmBeforeCreate = $confirm;
        return $this;
    }

    /**
     * Set display fields - fields to show when displaying entity info
     * Example: ['email', 'contact'] will show email or contact when displaying duplicates
     */
    public function displayFields(array $fields): self
    {
        $this->displayFields = $fields;
        return $this;
    }

    /**
     * Set parsing guidance for AI extraction
     * Example: "Extract as array with 'product' and 'quantity'. Parse quantity from input like 'Item 99' → quantity: 99"
     */
    public function parsingGuide(string $guide): self
    {
        $this->parsingGuide = $guide;
        return $this;
    }

    /**
     * Set creation prompt callback - generates custom prompt for entity creation
     * Callback receives: ($item, $itemName, $entityName)
     * Example: fn($item, $name, $entity) => "Product: {$name}\nCategory: Electronics"
     */
    public function creationPrompt(\Closure $callback): self
    {
        $this->creationPrompt = $callback;
        return $this;
    }

    /**
     * Set custom friendly name (overrides automatic plural conversion)
     * Example: ->friendlyName('customers')
     */
    public function friendlyName(string $name): self
    {
        $this->friendlyName = $name;
        return $this;
    }

    /**
     * Set custom name extractor callback
     * Callback receives: ($item) and returns extracted name
     * Example: fn($item) => $item['title'] ?? $item['label']
     */
    public function nameExtractor(\Closure $callback): self
    {
        $this->nameExtractor = $callback;
        return $this;
    }

    /**
     * Set custom category inferrer callback
     * Callback receives: ($name) and returns inferred category
     * Example: fn($name) => str_contains($name, 'laptop') ? 'Electronics' : 'General'
     */
    public function categoryInferrer(\Closure $callback): self
    {
        $this->categoryInferrer = $callback;
        return $this;
    }

    /**
     * Set custom field inferrer callback
     * Callback receives: ($existingData, $allFields, $entityType, $context) and returns inferred fields
     * Example: fn($data, $fields) => ['quantity' => 1, 'status' => 'active']
     */
    public function fieldInferrer(\Closure $callback): self
    {
        $this->fieldInferrer = $callback;
        return $this;
    }

    /**
     * Set fields to include from database entity when merging with user input
     * These fields will be included if not already provided by the user
     * Example: ['price', 'sale_price', 'sku', 'description']
     */
    public function includeFields(array $fields): self
    {
        $this->includeFields = $fields;
        return $this;
    }

    /**
     * Set base fields that are always included from entity
     * These are core fields like 'id', 'name', etc.
     * Default: ['id', 'name']
     * Example: ['id', 'name', 'sku', 'code']
     */
    public function baseFields(array $fields): self
    {
        $this->baseFields = $fields;
        return $this;
    }

    /**
     * Set required fields for array items (e.g., price and quantity for products)
     * These fields will be collected for each item in the array if missing
     * Example: ['sale_price', 'quantity']
     */
    public function requiredItemFields(array $fields): self
    {
        $this->requiredItemFields = $fields;
        return $this;
    }

    /**
     * Convert to array format for AI config
     */
    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'search_fields' => $this->searchFields ?: null,
            'filters' => $this->filters,
            'check_duplicates' => $this->checkDuplicates ?: null,
            'ask_on_duplicate' => $this->askOnDuplicate ?: null,
            'subflow' => $this->subflow,
            'multiple' => $this->multiple ?: null,
            'required' => $this->required,
            'description' => $this->description,
            'prompt' => $this->prompt,
            'validation' => $this->validation ?: null,
            'identifier_provider' => $this->identifierProvider,
            'confirm_before_create' => $this->confirmBeforeCreate ?: null,
            'display_fields' => $this->displayFields ?: null,
            'creation_prompt' => $this->creationPrompt,
            'friendly_name' => $this->friendlyName,
            'name_extractor' => $this->nameExtractor,
            'category_inferrer' => $this->categoryInferrer,
            'field_inferrer' => $this->fieldInferrer,
            'parsing_guide' => $this->parsingGuide,
            'include_fields' => $this->includeFields ?: null,
            'base_fields' => $this->baseFields ?: null,
            'required_item_fields' => $this->requiredItemFields ?: null,
        ], fn($value) => $value !== null);
    }

    /**
     * Create from array
     */
    public static function fromArray(array $config): self
    {
        return new self(
            model: $config['model'],
            searchFields: $config['search_fields'] ?? [],
            filters: $config['filters'] ?? null,
            checkDuplicates: $config['check_duplicates'] ?? false,
            askOnDuplicate: $config['ask_on_duplicate'] ?? false,
            subflow: $config['subflow'] ?? null,
            multiple: $config['multiple'] ?? false,
            required: $config['required'] ?? true,
            description: $config['description'] ?? null,
            prompt: $config['prompt'] ?? null,
            validation: $config['validation'] ?? [],
        );
    }
}
