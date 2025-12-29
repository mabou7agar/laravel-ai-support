<?php

namespace LaravelAIEngine\Traits;

/**
 * Unified AI Features Trait
 * 
 * Combines all AI-related traits into a single, easy-to-use trait.
 * Just add this one trait to get all AI capabilities.
 * 
 * Includes:
 * - HasAIActions: AI action execution
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
    use HasAIConfigBuilder;
    use AutoResolvesRelationships;
    
    /**
     * Boot the trait
     */
    public static function bootHasAIFeatures()
    {
        // Boot individual traits if they have boot methods
        if (method_exists(static::class, 'bootAutoResolvesRelationships')) {
            static::bootAutoResolvesRelationships();
        }
    }
}
