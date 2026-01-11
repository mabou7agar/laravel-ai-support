<?php

namespace LaravelAIEngine\Services\Agent\Traits;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use Illuminate\Support\Facades\Log;

/**
 * Trait for workflows that need to load and map file analysis data
 * 
 * Uses AI to intelligently map file analysis data to workflow fields
 */
trait LoadsFileAnalysisData
{
    /**
     * Get file analysis data from recent conversation messages
     */
    protected function getFileAnalysisFromConversation(string $sessionId): ?array
    {
        try {
            // Get conversation ID from session
            $conversationService = app(\LaravelAIEngine\Services\ConversationService::class);
            $conversationId = $conversationService->getOrCreateConversation($sessionId, auth()->id() ?? 1, 'openai', 'gpt-4o');
            
            if (!$conversationId) {
                return null;
            }
            
            // Use Message model to get recent messages
            $messages = \LaravelAIEngine\Models\Message::inConversation($conversationId)
                ->recent()
                ->limit(10)
                ->get();
            
            if ($messages->isEmpty()) {
                return null;
            }
            
            // Look for file analysis data in metadata
            foreach ($messages as $message) {
                if (!empty($message->metadata['file_analysis'])) {
                    Log::info('Found file analysis data in conversation', [
                        'session_id' => $sessionId,
                        'conversation_id' => $conversationId,
                        'message_id' => $message->message_id,
                    ]);
                    return $message->metadata['file_analysis'];
                }
            }
            
            Log::info('No file analysis data found in conversation', [
                'session_id' => $sessionId,
                'conversation_id' => $conversationId,
                'messages_checked' => $messages->count(),
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Failed to retrieve file analysis from conversation', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
        
        return null;
    }
    
    /**
     * Use AI to map file analysis data to workflow fields
     * 
     * @param array $fileAnalysis The raw file analysis data
     * @param array $fieldDefinitions The workflow's field definitions
     * @return array Mapped data ready for workflow
     */
    protected function mapFileAnalysisToFields(array $fileAnalysis, array $fieldDefinitions): array
    {
        $content = $fileAnalysis['content'] ?? '';
        $extractedData = $fileAnalysis['extracted_data'] ?? [];
        
        if (empty($content) && empty($extractedData)) {
            return [];
        }
        
        // Build AI prompt to map data
        $prompt = $this->buildMappingPrompt($content, $extractedData, $fieldDefinitions);
        
        try {
            $ai = app(\LaravelAIEngine\Services\AIEngineService::class);
            
            // Create proper AIRequest with required parameters
            $request = new \LaravelAIEngine\DTOs\AIRequest(
                prompt: $prompt,
                engine: new \LaravelAIEngine\Enums\EngineEnum('openai'),
                model: new \LaravelAIEngine\Enums\EntityEnum('gpt-4o-mini'),
                parameters: [],
                userId: null,
                conversationId: null,
                context: [],
                files: [],
                stream: false,
                systemPrompt: null,
                messages: [],
                maxTokens: 1000,
                temperature: 0.1
            );
            
            $response = $ai->generateText($request)->getContent();
            
            // Parse AI response as JSON
            $mappedData = json_decode($response, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($mappedData)) {
                Log::info('AI successfully mapped file analysis to workflow fields', [
                    'fields_mapped' => array_keys($mappedData),
                    'products_sample' => isset($mappedData['products'][0]) ? $mappedData['products'][0] : null,
                ]);
                return $mappedData;
            }
            
            Log::warning('AI response was not valid JSON', [
                'response' => $response,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to map file analysis using AI', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Fallback: try simple key matching
        return $this->fallbackMapping($extractedData, $fieldDefinitions);
    }
    
    /**
     * Build AI prompt for intelligent data mapping
     */
    protected function buildMappingPrompt(string $content, array $extractedData, array $fieldDefinitions): string
    {
        $fieldsDescription = [];
        foreach ($fieldDefinitions as $fieldName => $definition) {
            $desc = is_array($definition) ? ($definition['description'] ?? $definition['prompt'] ?? '') : $definition;
            $type = is_array($definition) ? ($definition['type'] ?? 'string') : 'string';
            $required = is_array($definition) ? ($definition['required'] ?? false) : false;
            
            $fieldsDescription[] = "- {$fieldName} ({$type}" . ($required ? ', required' : '') . "): {$desc}";
        }
        
        $fieldsText = implode("\n", $fieldsDescription);
        
        $extractedDataText = '';
        if (!empty($extractedData)) {
            $extractedDataText = "\n\nExtracted Key-Value Data:\n" . json_encode($extractedData, JSON_PRETTY_PRINT);
        }
        
        return <<<PROMPT
You are a data mapping assistant. Your task is to intelligently map file analysis data to specific workflow fields.

File Analysis Content:
{$content}
{$extractedDataText}

Target Workflow Fields:
{$fieldsText}

Instructions:
1. Analyze the file content and extracted data carefully
2. Map the data to the target workflow fields using intelligent inference
3. For identifier fields (customer_identifier, vendor_identifier, etc.), extract names, emails, or phone numbers
4. For array fields (products, items, etc.), extract all entries with their complete details
5. If numeric values like prices, quantities, or amounts are present, include them with appropriate keys
6. Use the field descriptions to understand what data is expected
7. Return ONLY a valid JSON object with the mapped fields (no explanations, no markdown)

Guidelines for common field types:
- Identifiers: Extract names, emails, or contact information
- Arrays: Include all items with their properties (name, quantity, sale_price, etc.)
- Dates: Use ISO format (YYYY-MM-DD) when possible
- Numbers: Use numeric values without currency symbols
- Prices: Use "sale_price" key for unit prices, rates, or selling prices

Return the mapped data as JSON:
PROMPT;
    }
    
    /**
     * Fallback mapping using simple key matching
     */
    protected function fallbackMapping(array $extractedData, array $fieldDefinitions): array
    {
        $mapped = [];
        
        foreach ($fieldDefinitions as $fieldName => $definition) {
            // Try exact match
            if (isset($extractedData[$fieldName])) {
                $mapped[$fieldName] = $extractedData[$fieldName];
                continue;
            }
            
            // Try fuzzy match (e.g., customer_name -> customer_identifier)
            foreach ($extractedData as $key => $value) {
                if (stripos($fieldName, $key) !== false || stripos($key, $fieldName) !== false) {
                    $mapped[$fieldName] = $value;
                    break;
                }
            }
        }
        
        return $mapped;
    }
    
    /**
     * Load and map file analysis data to workflow fields
     * Call this at the start of data collection
     */
    protected function loadAndMapFileAnalysis(UnifiedActionContext $context): bool
    {
        $sessionId = $context->get('session_id');
        if (empty($sessionId)) {
            return false;
        }
        
        // Get file analysis from conversation
        $fileAnalysis = $this->getFileAnalysisFromConversation($sessionId);
        if (!$fileAnalysis) {
            return false;
        }
        
        // Get field definitions from workflow
        $fieldDefinitions = method_exists($this, 'getFieldDefinitions') 
            ? $this->getFieldDefinitions() 
            : [];
            
        if (empty($fieldDefinitions)) {
            return false;
        }
        
        // Use AI to map data
        $mappedData = $this->mapFileAnalysisToFields($fileAnalysis, $fieldDefinitions);
        
        if (empty($mappedData)) {
            return false;
        }
        
        // Store mapped data in context
        $existingData = $context->get('collected_data', []);
        $context->set('collected_data', array_merge($existingData, $mappedData));
        
        Log::info('File analysis data loaded and mapped to workflow', [
            'session_id' => $sessionId,
            'fields_mapped' => array_keys($mappedData),
        ]);
        
        return true;
    }
}
