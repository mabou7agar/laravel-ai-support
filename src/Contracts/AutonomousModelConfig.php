<?php

namespace LaravelAIEngine\Contracts;

/**
 * Base class for model-specific autonomous configurations
 * Defines CRUD operations as tools that AI can use
 */
abstract class AutonomousModelConfig
{
    /**
     * Get the model class this config is for
     */
    abstract public static function getModelClass(): string;
    
    /**
     * Get the model name (lowercase, singular)
     */
    abstract public static function getName(): string;
    
    /**
     * Get human-readable description
     */
    abstract public static function getDescription(): string;
    
    /**
     * Get filter configuration for queries
     * 
     * @return array [
     *   'user_field' => 'created_by',
     *   'date_field' => 'created_at',
     *   'status_field' => 'status',
     *   'amount_field' => 'total',
     *   'eager_load' => ['relation1', 'relation2'],
     * ]
     */
    public static function getFilterConfig(): array
    {
        return [];
    }
    
    /**
     * Get CRUD tools that AI can use
     * 
     * @return array [
     *   'create_invoice' => [
     *     'description' => 'Create a new invoice',
     *     'parameters' => ['customer_id', 'items', ...],
     *     'handler' => function($data) { ... },
     *     'requires_confirmation' => true,
     *   ],
     *   'update_invoice' => [...],
     *   'delete_invoice' => [...],
     * ]
     */
    public static function getTools(): array
    {
        return [];
    }
    
    /**
     * Get allowed operations for a user
     * 
     * @param int|null $userId
     * @return array ['list', 'create', 'update', 'delete']
     */
    public static function getAllowedOperations(?int $userId): array
    {
        if (!$userId) {
            return ['list']; // Guests can only list
        }
        
        // Default: authenticated users can do everything
        // Override this in child classes for permission checks
        return ['list', 'create', 'update', 'delete'];
    }
    
    /**
     * Validate data before create/update
     * 
     * @param array $data
     * @param string $operation 'create' or 'update'
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate(array $data, string $operation = 'create'): array
    {
        // Override in child classes for custom validation
        return ['valid' => true, 'errors' => []];
    }
    
    /**
     * Transform data before saving
     * Useful for normalizing input, setting defaults, etc.
     * 
     * @param array $data
     * @param string $operation 'create' or 'update'
     * @return array
     */
    public static function transformData(array $data, string $operation = 'create'): array
    {
        // Override in child classes for custom transformations
        return $data;
    }
}
