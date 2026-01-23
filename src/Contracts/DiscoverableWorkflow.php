<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for workflows that can be automatically discovered
 * 
 * Workflows implementing this interface will be automatically registered
 * and the AI agent will intelligently route to them based on their goal.
 */
interface DiscoverableWorkflow
{
    /**
     * Get the workflow goal/description
     * 
     * The AI agent uses this to understand what this workflow does
     * and intelligently routes user requests to the appropriate workflow.
     * 
     * Be specific and clear about what this workflow accomplishes.
     * 
     * Examples:
     * - "Create customer invoice with line items and pricing"
     * - "Record vendor bill/expense with products and amounts"
     * - "Register new product with pricing and category"
     * 
     * @return string Clear description of what this workflow accomplishes
     */
    public static function getGoal(): string;
    
    /**
     * Get trigger keywords/phrases for this workflow (optional, for backward compatibility)
     * 
     * These provide fallback keyword matching if AI routing fails.
     * The AI agent primarily uses the goal for intelligent routing.
     * 
     * @return array List of keywords/phrases (optional)
     */
    public static function getTriggers(): array;
    
    /**
     * Get priority for this workflow (optional)
     * 
     * Higher priority workflows are presented first to the AI for consideration.
     * Default is 0.
     * 
     * @return int Priority level (higher = considered first)
     */
    public static function getPriority(): int;
}
