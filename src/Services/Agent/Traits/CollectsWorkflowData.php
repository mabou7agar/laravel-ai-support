<?php

namespace LaravelAIEngine\Services\Agent\Traits;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\Services\Agent\WorkflowDataCollector;

/**
 * Trait for workflows that need to collect data from users
 * 
 * Provides easy-to-use methods for AI-powered data collection
 * Includes automatic file analysis loading and AI-powered mapping
 */
trait CollectsWorkflowData
{
    use LoadsFileAnalysisData;
    
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
     * Check if workflow should auto-load file analysis
     * Override this to enable/disable file analysis loading
     */
    protected function shouldAutoLoadFileAnalysis(): bool
    {
        return true; // Enabled by default
    }
    
    /**
     * Collect data from user messages
     * Automatically tries to load and map file analysis data first
     */
    protected function collectData(UnifiedActionContext $context): ActionResult
    {
        // Try to auto-load file analysis data if enabled
        if ($this->shouldAutoLoadFileAnalysis()) {
            $sessionId = $context->sessionId ?? $context->get('session_id');
            
            if ($sessionId) {
                $fileAnalysis = $this->getFileAnalysisFromConversation($sessionId);
                
                if ($fileAnalysis) {
                    // Use AI to map file data to workflow fields
                    $mappedData = $this->mapFileAnalysisToFields($fileAnalysis, $this->getFieldDefinitions());
                    
                    if (!empty($mappedData)) {
                        // Store mapped data
                        $existingData = $context->get('collected_data', []);
                        $context->set('collected_data', array_merge($existingData, $mappedData));
                        
                        // Check if we have all required fields
                        $fieldDefs = $this->getFieldDefinitions();
                        $hasAllRequired = true;
                        
                        foreach ($fieldDefs as $fieldName => $definition) {
                            $isRequired = is_array($definition) ? ($definition['required'] ?? false) : false;
                            if ($isRequired && empty($mappedData[$fieldName])) {
                                $hasAllRequired = false;
                                break;
                            }
                        }
                        
                        if ($hasAllRequired) {
                            \Illuminate\Support\Facades\Log::info('AI successfully mapped file data to workflow', [
                                'workflow' => get_class($this),
                                'fields_mapped' => array_keys($mappedData),
                            ]);
                            
                            return ActionResult::success(
                                message: "I found data from your uploaded file. Let me process it..."
                            );
                        }
                    }
                }
            }
        }
        
        // Otherwise, collect data normally
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
