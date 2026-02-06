<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use Illuminate\Support\Facades\Log;

/**
 * Extracts tool parameters from conversation context
 * 
 * Reusable handler for extracting entity IDs and parameters from:
 * - Conversation history
 * - Current message
 * - Numeric selections
 * - Entity lists from previous queries
 */
class ToolParameterExtractor
{
    /**
     * Extract parameters from conversation context
     *
     * @param string $message Current user message
     * @param array $conversationHistory Full conversation history
     * @param array $paramSchema Tool parameter schema
     * @param string|null $modelName Model name for pattern matching (e.g., 'invoice', 'customer')
     * @param array|null $lastQueryState Last query state for numeric selections
     * @return array Extracted parameters
     */
    public static function extract(
        string $message,
        array $conversationHistory,
        array $paramSchema = [],
        ?string $modelName = null,
        ?array $lastQueryState = null
    ): array {
        Log::channel('ai-engine')->info('ToolParameterExtractor: Starting extraction', [
            'message' => $message,
            'model_name' => $modelName,
            'has_history' => !empty($conversationHistory),
            'history_count' => count($conversationHistory),
            'has_query_state' => !empty($lastQueryState),
        ]);
        
        $params = [];
        
        // Strategy 1: Extract from conversation history if model name provided
        if ($modelName) {
            Log::channel('ai-engine')->debug('ToolParameterExtractor: Trying strategy 1 - extract from history');
            $historyParams = self::extractFromHistory($conversationHistory, $modelName);
            if (!empty($historyParams)) {
                Log::channel('ai-engine')->info('ToolParameterExtractor: Strategy 1 SUCCESS', $historyParams);
            }
            $params = array_merge($params, $historyParams);
        }
        
        // Strategy 2: Extract from current message if model name provided
        if ($modelName && empty($params['id'])) {
            Log::channel('ai-engine')->debug('ToolParameterExtractor: Trying strategy 2 - extract from message');
            $messageParams = self::extractFromMessage($message, $modelName);
            if (!empty($messageParams)) {
                Log::channel('ai-engine')->info('ToolParameterExtractor: Strategy 2 SUCCESS', $messageParams);
            }
            $params = array_merge($params, $messageParams);
        }
        
        // Strategy 3: Handle numeric selection from entity list
        if (empty($params['id']) && $lastQueryState) {
            Log::channel('ai-engine')->debug('ToolParameterExtractor: Trying strategy 3 - numeric selection', [
                'entity_ids' => $lastQueryState['entity_ids'] ?? null,
            ]);
            $numericParams = self::extractFromNumericSelection($message, $lastQueryState);
            if (!empty($numericParams)) {
                Log::channel('ai-engine')->info('ToolParameterExtractor: Strategy 3 SUCCESS', $numericParams);
            }
            $params = array_merge($params, $numericParams);
        }
        
        // Strategy 4: Extract generic ID patterns if no model name
        if (empty($params['id']) && !$modelName) {
            Log::channel('ai-engine')->debug('ToolParameterExtractor: Trying strategy 4 - generic ID');
            $genericParams = self::extractGenericId($message, $conversationHistory);
            if (!empty($genericParams)) {
                Log::channel('ai-engine')->info('ToolParameterExtractor: Strategy 4 SUCCESS', $genericParams);
            }
            $params = array_merge($params, $genericParams);
        }
        
        Log::channel('ai-engine')->info('ToolParameterExtractor: Extraction complete', [
            'final_params' => $params,
            'success' => !empty($params),
        ]);
        
        return $params;
    }
    
    /**
     * Extract entity ID from conversation history
     */
    protected static function extractFromHistory(array $conversationHistory, string $modelName): array
    {
        $params = [];
        $pattern = '/' . preg_quote($modelName, '/') . '\s*#?(\d+)/i';
        
        Log::channel('ai-engine')->debug('ToolParameterExtractor: Searching history', [
            'pattern' => $pattern,
            'messages_to_search' => count($conversationHistory),
        ]);
        
        // Search recent messages for entity references
        foreach (array_reverse($conversationHistory) as $index => $msg) {
            $content = $msg['content'] ?? '';
            
            if (preg_match($pattern, $content, $matches)) {
                $params['id'] = (int)$matches[1];
                Log::channel('ai-engine')->info('ToolParameterExtractor: Found ID in history', [
                    'message_index' => $index,
                    'content_snippet' => substr($content, 0, 100),
                    'extracted_id' => $params['id'],
                ]);
                break;
            }
        }
        
        return $params;
    }
    
    /**
     * Extract entity ID from current message
     */
    protected static function extractFromMessage(string $message, string $modelName): array
    {
        $params = [];
        
        // Pattern: "Invoice #218", "invoice 218", "Customer #5"
        $pattern = '/' . preg_quote($modelName, '/') . '\s*#?(\d+)/i';
        if (preg_match($pattern, $message, $matches)) {
            $params['id'] = (int)$matches[1];
        }
        
        return $params;
    }
    
    /**
     * Extract ID from numeric selection (e.g., user says "2" to select second item)
     */
    protected static function extractFromNumericSelection(string $message, array $lastQueryState): array
    {
        $params = [];
        
        if (!is_numeric(trim($message))) {
            return $params;
        }
        
        $index = (int)trim($message) - 1;
        
        // Check for entity IDs in last query state
        $entityIds = $lastQueryState['entity_ids'] ?? [];
        if (isset($entityIds[$index])) {
            $params['id'] = $entityIds[$index];
        }
        
        return $params;
    }
    
    /**
     * Extract generic ID patterns when model name is unknown
     */
    protected static function extractGenericId(string $message, array $conversationHistory): array
    {
        $params = [];
        
        // Try common patterns: "#218", "id 218", etc.
        if (preg_match('/#(\d+)/', $message, $matches)) {
            $params['id'] = (int)$matches[1];
            return $params;
        }
        
        if (preg_match('/\bid[:\s]+(\d+)/i', $message, $matches)) {
            $params['id'] = (int)$matches[1];
            return $params;
        }
        
        return $params;
    }
    
    /**
     * Extract parameters with context metadata
     * 
     * Enhanced version that includes metadata from UnifiedActionContext
     */
    public static function extractWithMetadata(
        string $message,
        $context,
        array $paramSchema = [],
        ?string $modelName = null
    ): array {
        $conversationHistory = $context->conversationHistory ?? [];
        $lastEntityList = $context->metadata['last_entity_list'] ?? null;
        
        return self::extract(
            $message,
            $conversationHistory,
            $paramSchema,
            $modelName,
            $lastEntityList
        );
    }
}
