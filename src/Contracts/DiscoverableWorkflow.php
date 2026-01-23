<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for workflows that can be automatically discovered
 * 
 * Workflows implementing this interface will be automatically registered
 * for intent detection without needing manual configuration.
 */
interface DiscoverableWorkflow
{
    /**
     * Get trigger keywords/phrases for this workflow
     * 
     * These are used for intent detection to route user messages
     * to the appropriate workflow.
     * 
     * @return array List of keywords/phrases that should trigger this workflow
     */
    public static function getTriggers(): array;
    
    /**
     * Get the workflow goal/description
     * 
     * This helps the AI understand what this workflow does
     * and can be used for more intelligent routing.
     * 
     * @return string Description of what this workflow accomplishes
     */
    public static function getGoal(): string;
    
    /**
     * Get priority for this workflow (optional)
     * 
     * Higher priority workflows are checked first during intent detection.
     * Default is 0.
     * 
     * @return int Priority level (higher = checked first)
     */
    public static function getPriority(): int;
}
