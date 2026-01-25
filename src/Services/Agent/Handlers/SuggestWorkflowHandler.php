<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Services\AIEngineService;
use Illuminate\Support\Facades\Log;

/**
 * Handles suggestions when message contains data matching a workflow
 * Dynamically extracts data based on workflow field definitions
 */
class SuggestWorkflowHandler implements MessageHandlerInterface
{
    public function __construct(
        protected AIEngineService $ai
    ) {}

    public function handle(
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): AgentResponse {
        Log::channel('ai-engine')->info('Handling workflow suggestion', [
            'session_id' => $context->sessionId,
            'suggested_workflows' => $options['suggested_workflows'] ?? [],
        ]);
        
        $suggestedWorkflows = $options['suggested_workflows'] ?? [];
        
        // Get field definitions from the first suggested workflow
        $workflowFields = $this->getWorkflowFields($suggestedWorkflows[0] ?? []);
        
        // Extract data from message based on workflow fields (generic)
        $extractedData = $this->extractDataForWorkflow($message, $workflowFields, $suggestedWorkflows[0] ?? []);
        
        // Store extracted data and suggested workflow in context for when user confirms
        $context->set('suggested_transaction_data', $extractedData);
        $context->set('original_message', $message);
        
        // Store the first suggested workflow class for auto-start when user confirms
        if (!empty($suggestedWorkflows) && isset($suggestedWorkflows[0]['workflow_class'])) {
            $context->set('suggested_workflow_class', $suggestedWorkflows[0]['workflow_class']);
        }
        
        // Build suggestion message dynamically based on extracted data
        $suggestionMessage = $this->buildSuggestionMessage($extractedData, $suggestedWorkflows, $workflowFields);
        
        // Build interactive actions for quick responses
        $actions = $this->buildSuggestionActions($suggestedWorkflows);
        
        // Convert InteractiveAction objects to arrays for the response
        $actionsArray = array_map(fn($a) => $a->toArray(), $actions);
        
        return AgentResponse::needsUserInput(
            message: $suggestionMessage,
            data: ['extracted_data' => $extractedData],
            actions: $actionsArray,
            context: $context
        );
    }
    
    public function canHandle(string $action): bool
    {
        return $action === 'suggest_workflow';
    }
    
    /**
     * Get field definitions from workflow class
     */
    protected function getWorkflowFields(array $workflowInfo): array
    {
        $workflowClass = $workflowInfo['workflow_class'] ?? null;
        
        if (!$workflowClass || !class_exists($workflowClass)) {
            return [];
        }
        
        try {
            $workflow = app($workflowClass);
            
            // Try to get config from workflow
            if (method_exists($workflow, 'getConfig')) {
                $config = $workflow->getConfig();
                return $config['fields'] ?? [];
            }
            
            // Fallback: use reflection to get config
            $reflection = new \ReflectionClass($workflow);
            if ($reflection->hasMethod('config')) {
                $configMethod = $reflection->getMethod('config');
                $configMethod->setAccessible(true);
                $config = $configMethod->invoke($workflow);
                return $config['fields'] ?? [];
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->debug('Could not get workflow fields', [
                'workflow' => $workflowClass,
                'error' => $e->getMessage(),
            ]);
        }
        
        return [];
    }
    
    /**
     * Extract data from message based on workflow field definitions
     */
    protected function extractDataForWorkflow(string $message, array $fields, array $workflowInfo): array
    {
        try {
            $workflowGoal = $workflowInfo['description'] ?? $workflowInfo['goal'] ?? 'process this data';
            
            // Build dynamic extraction prompt based on workflow fields
            $prompt = "Extract data from this message for: {$workflowGoal}\n";
            $prompt .= "Message: \"{$message}\"\n\n";
            $prompt .= "Extract the following fields as JSON:\n";
            
            // Build field list from workflow config or use generic detection
            if (!empty($fields)) {
                foreach ($fields as $fieldName => $fieldConfig) {
                    $description = is_array($fieldConfig) 
                        ? ($fieldConfig['description'] ?? $fieldName)
                        : $fieldConfig;
                    $prompt .= "- {$fieldName}: {$description}\n";
                }
            } else {
                // Generic extraction when no fields defined
                $prompt .= "- Identify the main entity/person/company mentioned\n";
                $prompt .= "- Extract any items/products with quantities and prices\n";
                $prompt .= "- Extract any dates, amounts, or other relevant data\n";
            }
            
            $prompt .= "\nReturn valid JSON only. Use snake_case keys. ";
            $prompt .= "For arrays of items, use format: [{\"name\":\"...\",\"quantity\":N,\"price\":N}]\n";
            $prompt .= "JSON:";
            
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                maxTokens: 300,
                temperature: 0
            ));
            
            $content = $response->getContent();
            
            // Clean up code blocks if present
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
                $content = $matches[1];
            }
            
            $data = json_decode($content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::channel('ai-engine')->info('Extracted data for workflow', [
                    'workflow' => $workflowInfo['workflow'] ?? 'unknown',
                    'fields_extracted' => array_keys($data),
                ]);
                return $data;
            }
            
            return [];
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Failed to extract workflow data', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
    
    /**
     * Build the suggestion message dynamically based on extracted data
     */
    protected function buildSuggestionMessage(array $extractedData, array $suggestedWorkflows, array $fields): string
    {
        $message = "📋 I noticed some data that might be useful. ";
        
        if (!empty($extractedData)) {
            $message .= "Here's what I extracted:\n\n";
            
            // Display extracted data dynamically
            foreach ($extractedData as $key => $value) {
                $label = $this->formatFieldLabel($key);
                
                if (is_array($value)) {
                    // Handle array fields (like items/products)
                    if ($this->isItemsArray($value)) {
                        $message .= "**{$label}:**\n";
                        $total = 0;
                        foreach ($value as $item) {
                            $name = $item['name'] ?? $item['product'] ?? 'Unknown';
                            $qty = $item['quantity'] ?? $item['qty'] ?? 1;
                            $price = $item['price'] ?? $item['amount'] ?? $item['sale_price'] ?? 0;
                            $lineTotal = $qty * $price;
                            $total += $lineTotal;
                            $message .= "  • {$name} × {$qty} @ \${$price} = \${$lineTotal}\n";
                        }
                        $message .= "\n**Total:** \${$total}\n";
                    } else {
                        $message .= "**{$label}:** " . json_encode($value) . "\n";
                    }
                } else {
                    $message .= "**{$label}:** {$value}\n";
                }
            }
        }
        
        $message .= "\n**Would you like me to:**\n";
        
        foreach ($suggestedWorkflows as $workflow) {
            $label = $workflow['label'] ?? 'Create Document';
            $description = $workflow['description'] ?? '';
            $message .= "• **{$label}** - {$description}\n";
        }
        
        $message .= "\nJust say 'yes' to proceed, or 'no' to cancel.";
        
        return $message;
    }
    
    /**
     * Check if array looks like items/products array
     */
    protected function isItemsArray(array $arr): bool
    {
        if (empty($arr)) return false;
        $first = reset($arr);
        return is_array($first) && (isset($first['name']) || isset($first['product']) || isset($first['quantity']));
    }
    
    /**
     * Format field key to readable label
     */
    protected function formatFieldLabel(string $key): string
    {
        // Convert snake_case to Title Case
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }
    
    /**
     * Build interactive action buttons
     */
    protected function buildSuggestionActions(array $suggestedWorkflows): array
    {
        $actions = [];
        
        foreach ($suggestedWorkflows as $index => $workflow) {
            $workflowType = $workflow['workflow'] ?? 'invoice';
            $label = $workflow['label'] ?? 'Create';
            
            $actions[] = InteractiveAction::quickReply(
                "create_{$workflowType}_{$index}",
                $label,
                "create {$workflowType}",
                $workflow['description'] ?? null
            );
        }
        
        $actions[] = InteractiveAction::quickReply(
            'cancel_suggestion',
            'Cancel',
            'no',
            'Cancel this suggestion'
        );
        
        return $actions;
    }
}
