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
