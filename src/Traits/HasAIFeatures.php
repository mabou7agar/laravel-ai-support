<?php

namespace LaravelAIEngine\Traits;

/**
 * Unified AI Features Trait
 * 
 * Combines all AI-related traits into a single, easy-to-use trait.
 * Just add this one trait to get all AI capabilities.
 * 
 * Includes:
 * - HasAIActions: AI action execution (initializeAI, executeAI)
 * - HasAIConfigBuilder: Fluent configuration API
 * - AutoResolvesRelationships: Automatic relationship resolution
 * 
 * Usage:
 * ```php
 * class Invoice extends Model
 * {
 *     use HasAIFeatures;
 *     
 *     public function initializeAI(): array
 *     {
 *         return $this->aiConfig()
 *             ->description('Customer invoice')
 *             ->autoRelationship('customer_id', 'Customer', User::class)
 *             ->arrayField('items', 'Items', [...])
 *             ->build();
 *     }
 * }
 * ```
 */
trait HasAIFeatures
{
    use HasAIActions;
    use HasAIConfigBuilder;
    use AutoResolvesRelationships;
    
    /**
     * Get AI configuration statically (for WorkflowConfigBuilder)
     */
    public static function getAIConfig(): array
    {
        $instance = new static();
        return $instance->initializeAI();
    }
    
    /**
     * Boot the trait
     */
    public static function bootHasAIFeatures()
    {
        // Boot individual traits if they have boot methods
        // Currently no boot methods needed for included traits
    }
}
