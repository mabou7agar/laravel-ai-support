<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for models with custom AI configuration
 * 
 * Models implementing this interface can provide custom AI behavior
 * by defining static methods for initialization and execution.
 */
interface AIConfigurable
{
    /**
     * Initialize AI configuration for the model
     * 
     * @return array AI configuration array
     */
    public static function customInitializeAI(): array;
    
    /**
     * Execute AI action on the model
     * 
     * @param string $action The action to perform (create, update, delete)
     * @param array $data The data for the action
     * @return mixed The result of the action
     */
    public static function customExecuteAI(string $action, array $data);
}
