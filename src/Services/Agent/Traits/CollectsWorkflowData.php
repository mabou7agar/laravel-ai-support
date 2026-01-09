<?php

namespace LaravelAIEngine\Services\Agent\Traits;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\Agent\WorkflowDataCollector;

/**
 * Trait for workflows that need to collect data from users
 * 
 * Provides easy-to-use methods for AI-powered data collection
 */
trait CollectsWorkflowData
{
    protected ?WorkflowDataCollector $dataCollector = null;
    
    /**
     * Get or create data collector instance
     */
    protected function dataCollector(): WorkflowDataCollector
    {
        if (!$this->dataCollector) {
            $this->dataCollector = app(WorkflowDataCollector::class);
        }
        
        return $this->dataCollector;
    }
    
    /**
     * Define the fields this workflow needs to collect
     * 
     * Override this method in your workflow
     */
    abstract protected function getFieldDefinitions(): array;
    
    /**
     * Collect data from user messages
     */
    protected function collectData(UnifiedActionContext $context): ActionResult
    {
        return $this->dataCollector()->collectData(
            $context,
            $this->getFieldDefinitions()
        );
    }
    
    /**
     * Check if all required data has been collected
     */
    protected function isDataComplete(UnifiedActionContext $context): bool
    {
        return $this->dataCollector()->isComplete(
            $context,
            $this->getFieldDefinitions()
        );
    }
    
    /**
     * Get a specific field value
     */
    protected function getFieldValue(UnifiedActionContext $context, string $fieldName, $default = null)
    {
        return $this->dataCollector()->getField($context, $fieldName, $default);
    }
    
    /**
     * Set a specific field value
     */
    protected function setFieldValue(UnifiedActionContext $context, string $fieldName, $value): void
    {
        $this->dataCollector()->setField($context, $fieldName, $value);
    }
    
    /**
     * Get all collected data
     */
    protected function getCollectedData(UnifiedActionContext $context): array
    {
        return $context->get('collected_data', []);
    }
    
    /**
     * Validate collected data
     */
    protected function validateCollectedData(UnifiedActionContext $context): array
    {
        $data = $this->getCollectedData($context);
        return $this->dataCollector()->validateData($data, $this->getFieldDefinitions());
    }
    
    /**
     * Clear collected data
     */
    protected function clearCollectedData(UnifiedActionContext $context): void
    {
        $this->dataCollector()->clear($context);
    }
}
