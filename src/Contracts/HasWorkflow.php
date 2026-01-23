<?php

namespace LaravelAIEngine\Contracts;

/**
 * Interface for models that have an associated AI workflow
 * 
 * Models implementing this interface will be automatically discovered
 * and their workflows will be available for intent detection.
 */
interface HasWorkflow
{
    /**
     * Get the workflow class associated with this model
     * 
     * @return string Fully qualified workflow class name
     */
    public static function getWorkflowClass(): string;
    
    /**
     * Get trigger keywords for this workflow
     * 
     * @return array List of keywords/phrases that should trigger this workflow
     */
    public static function getWorkflowTriggers(): array;
}
