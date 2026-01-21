<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * AI-Enhanced Workflow Service
 * 
 * Provides AI-driven capabilities for workflow operations:
 * - Validation with context awareness
 * - Entity matching with semantic search
 * - Duplicate detection
 * - Prompt generation
 * - Error message generation
 * - Field type inference
 */
class AIEnhancedWorkflowService
{
    protected $ai;

    public function __construct(AIEngineService $ai)
    {
        $this->ai = $ai;
    }

    /**
     * AI-driven validation with context awareness
     */
    public function validateField(
        string $fieldName,
        $value,
        array $fieldDefinition,
        array $context = []
    ): array {
        try {
            $prompt = "You are validating user input for a conversational workflow.\n\n";
            $prompt .= "FIELD: {$fieldName}\n";
            $prompt .= "DESCRIPTION: " . ($fieldDefinition['description'] ?? 'N/A') . "\n";
            $prompt .= "TYPE: " . ($fieldDefinition['type'] ?? 'string') . "\n";
            $prompt .= "VALUE PROVIDED: " . json_encode($value) . "\n\n";
            
            if (!empty($context)) {
                $prompt .= "CONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\n";
            }
            
            $prompt .= "VALIDATION RULES:\n";
            $prompt .= "1. Check if value matches the expected type\n";
            $prompt .= "2. Check if value makes sense in the business context\n";
            $prompt .= "3. Check for common mistakes (typos, wrong format, unrealistic values)\n";
            $prompt .= "4. Consider the conversation context\n\n";
            
            if (!empty($fieldDefinition['validation'])) {
                $prompt .= "ADDITIONAL RULES: " . $fieldDefinition['validation'] . "\n\n";
            }
            
            $prompt .= "Respond with JSON:\n";
            $prompt .= '{"valid": true/false, "error": "error message if invalid", "suggestion": "helpful suggestion if any"}';

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }

        } catch (\Exception $e) {
            Log::error('AI validation failed', ['error' => $e->getMessage()]);
        }

        // Fallback: assume valid
        return ['valid' => true];
    }

    /**
     * AI-driven entity matching with semantic understanding
     */
    public function findMatchingEntity(
        string $modelClass,
        string $userQuery,
        array $searchFields,
        array $conversationContext = [],
        int $limit = 5
    ): array {
        try {
            // Get sample entities for context
            $samples = $modelClass::take(20)->get()->map(function($entity) use ($searchFields) {
                $data = [];
                foreach ($searchFields as $field) {
                    $data[$field] = $entity->$field ?? null;
                }
                $data['id'] = $entity->id;
                return $data;
            })->toArray();

            $prompt = "You are finding matching entities based on a user's natural language query.\n\n";
            $prompt .= "USER QUERY: \"{$userQuery}\"\n\n";
            $prompt .= "AVAILABLE ENTITIES:\n" . json_encode($samples, JSON_PRETTY_PRINT) . "\n\n";
            
            if (!empty($conversationContext)) {
                $prompt .= "CONVERSATION CONTEXT:\n" . json_encode($conversationContext, JSON_PRETTY_PRINT) . "\n\n";
            }
            
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "1. Find entities that match the user's query\n";
            $prompt .= "2. Consider semantic similarity, not just exact matches\n";
            $prompt .= "3. Understand context and references (\"the one we discussed\", \"last customer\", etc.)\n";
            $prompt .= "4. Return up to {$limit} best matches\n";
            $prompt .= "5. Include confidence score (0-1) for each match\n\n";
            $prompt .= "Respond with JSON array:\n";
            $prompt .= '[{"id": 123, "confidence": 0.95, "reason": "why this matches"}]';

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 500,
                temperature: 0.3
            ));

            $matches = json_decode($response->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($matches)) {
                return $matches;
            }

        } catch (\Exception $e) {
            Log::error('AI entity matching failed', ['error' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * AI-driven duplicate detection
     */
    public function isDuplicate(
        array $newData,
        array $existingRecords,
        string $entityType
    ): array {
        try {
            $prompt = "You are detecting if a new record is a duplicate of existing records.\n\n";
            $prompt .= "NEW RECORD:\n" . json_encode($newData, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "EXISTING RECORDS:\n" . json_encode($existingRecords, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "ENTITY TYPE: {$entityType}\n\n";
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "1. Check if the new record is a duplicate of any existing record\n";
            $prompt .= "2. Consider semantic similarity, not just exact matches\n";
            $prompt .= "3. Account for typos, abbreviations, different formats\n";
            $prompt .= "4. Examples:\n";
            $prompt .= "   - 'John Smith' vs 'J. Smith' → likely duplicate\n";
            $prompt .= "   - 'john@test.com' vs 'john@test.com' → exact duplicate\n";
            $prompt .= "   - 'Macbook Pro M4' vs 'MacBook Pro M4 Max' → similar but NOT duplicate\n\n";
            $prompt .= "Respond with JSON:\n";
            $prompt .= '{"is_duplicate": true/false, "matching_id": 123, "confidence": 0.95, "reason": "explanation"}';

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }

        } catch (\Exception $e) {
            Log::error('AI duplicate detection failed', ['error' => $e->getMessage()]);
        }

        return ['is_duplicate' => false];
    }

    /**
     * AI-driven prompt generation
     */
    public function generatePrompt(
        string $fieldName,
        array $fieldDefinition,
        array $conversationHistory = [],
        array $collectedData = []
    ): string {
        try {
            $prompt = "You are generating a natural, conversational prompt to ask the user for information.\n\n";
            $prompt .= "FIELD TO COLLECT: {$fieldName}\n";
            $prompt .= "DESCRIPTION: " . ($fieldDefinition['description'] ?? 'N/A') . "\n";
            $prompt .= "TYPE: " . ($fieldDefinition['type'] ?? 'string') . "\n";
            $prompt .= "REQUIRED: " . ($fieldDefinition['required'] ? 'yes' : 'no') . "\n\n";
            
            if (!empty($collectedData)) {
                $prompt .= "ALREADY COLLECTED:\n" . json_encode($collectedData, JSON_PRETTY_PRINT) . "\n\n";
            }
            
            if (!empty($conversationHistory)) {
                $recentHistory = array_slice($conversationHistory, -3);
                $prompt .= "RECENT CONVERSATION:\n";
                foreach ($recentHistory as $msg) {
                    $prompt .= "{$msg['role']}: {$msg['content']}\n";
                }
                $prompt .= "\n";
            }
            
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "1. Generate a natural, friendly prompt\n";
            $prompt .= "2. Match the conversation tone and style\n";
            $prompt .= "3. Be concise but clear\n";
            $prompt .= "4. If optional, mention it's optional\n";
            $prompt .= "5. Provide context if helpful\n\n";
            $prompt .= "Examples:\n";
            $prompt .= "- 'Great! Now, what's the customer's name?'\n";
            $prompt .= "- 'What price should we set for this product?'\n";
            $prompt .= "- 'Would you like to add a phone number? (optional)'\n\n";
            $prompt .= "Return ONLY the prompt text, no JSON, no explanation.";

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            return trim($response->content);

        } catch (\Exception $e) {
            Log::error('AI prompt generation failed', ['error' => $e->getMessage()]);
        }

        // Fallback
        return $fieldDefinition['prompt'] ?? "What is the {$fieldName}?";
    }

    /**
     * AI-driven error message generation
     */
    public function generateErrorMessage(
        string $fieldName,
        $value,
        string $validationError,
        array $context = []
    ): string {
        try {
            $prompt = "You are generating a helpful, friendly error message for a user.\n\n";
            $prompt .= "FIELD: {$fieldName}\n";
            $prompt .= "USER PROVIDED: " . json_encode($value) . "\n";
            $prompt .= "VALIDATION ERROR: {$validationError}\n\n";
            
            if (!empty($context)) {
                $prompt .= "CONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT) . "\n\n";
            }
            
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "1. Generate a friendly, helpful error message\n";
            $prompt .= "2. Explain what's wrong in simple terms\n";
            $prompt .= "3. Suggest how to fix it if possible\n";
            $prompt .= "4. Be conversational, not robotic\n";
            $prompt .= "5. Keep it concise\n\n";
            $prompt .= "Examples:\n";
            $prompt .= "- 'Hmm, that doesn't look like a valid email. Could you double-check it?'\n";
            $prompt .= "- 'The price seems a bit high. Did you mean $50 instead of $5000?'\n";
            $prompt .= "- 'Oops! The name field can't be empty. What's the customer's name?'\n\n";
            $prompt .= "Return ONLY the error message, no JSON, no explanation.";

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            return trim($response->content);

        } catch (\Exception $e) {
            Log::error('AI error message generation failed', ['error' => $e->getMessage()]);
        }

        // Fallback
        return "The {$fieldName} field has an error: {$validationError}";
    }

    /**
     * AI-driven field type inference
     */
    public function inferFieldType(
        string $fieldName,
        ?string $description = null,
        array $sampleData = []
    ): string {
        try {
            $prompt = "You are inferring the data type of a field based on its name, description, and sample data.\n\n";
            $prompt .= "FIELD NAME: {$fieldName}\n";
            
            if ($description) {
                $prompt .= "DESCRIPTION: {$description}\n";
            }
            
            if (!empty($sampleData)) {
                $prompt .= "SAMPLE DATA: " . json_encode($sampleData) . "\n";
            }
            
            $prompt .= "\nAVAILABLE TYPES:\n";
            $prompt .= "- string: text, names, descriptions\n";
            $prompt .= "- integer: whole numbers, counts, IDs\n";
            $prompt .= "- number: decimals, prices, measurements\n";
            $prompt .= "- email: email addresses\n";
            $prompt .= "- phone: phone numbers\n";
            $prompt .= "- date: dates\n";
            $prompt .= "- boolean: yes/no, true/false\n";
            $prompt .= "- array: lists, multiple items\n";
            $prompt .= "- url: web addresses\n\n";
            $prompt .= "Return ONLY the type name, nothing else.";

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 20,
                temperature: 0
            ));

            $type = strtolower(trim($response->content));
            
            // Validate it's a known type
            $validTypes = ['string', 'integer', 'number', 'email', 'phone', 'date', 'boolean', 'array', 'url'];
            if (in_array($type, $validTypes)) {
                return $type;
            }

        } catch (\Exception $e) {
            Log::error('AI field type inference failed', ['error' => $e->getMessage()]);
        }

        // Fallback
        return 'string';
    }

    /**
     * AI-driven missing field detection
     */
    public function whatElseDoWeNeed(
        array $collectedData,
        array $fieldDefinitions,
        string $goal,
        array $conversationHistory = []
    ): array {
        try {
            $prompt = "You are determining what information is still needed to complete a task.\n\n";
            $prompt .= "GOAL: {$goal}\n\n";
            $prompt .= "COLLECTED DATA:\n" . json_encode($collectedData, JSON_PRETTY_PRINT) . "\n\n";
            $prompt .= "AVAILABLE FIELDS:\n";
            foreach ($fieldDefinitions as $name => $def) {
                $required = $def['required'] ?? false;
                $desc = $def['description'] ?? '';
                $prompt .= "- {$name}" . ($required ? ' (required)' : ' (optional)') . ": {$desc}\n";
            }
            $prompt .= "\n";
            
            if (!empty($conversationHistory)) {
                $prompt .= "CONVERSATION CONTEXT:\n";
                $recentHistory = array_slice($conversationHistory, -3);
                foreach ($recentHistory as $msg) {
                    $prompt .= "{$msg['role']}: {$msg['content']}\n";
                }
                $prompt .= "\n";
            }
            
            $prompt .= "INSTRUCTIONS:\n";
            $prompt .= "1. Determine what required fields are still missing\n";
            $prompt .= "2. Consider if we have enough information to proceed\n";
            $prompt .= "3. Optional fields can be skipped if we have the essentials\n";
            $prompt .= "4. Return fields in order of importance\n\n";
            $prompt .= "Respond with JSON array of field names:\n";
            $prompt .= '["field1", "field2"]';

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $missing = json_decode($response->content, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($missing)) {
                return $missing;
            }

        } catch (\Exception $e) {
            Log::error('AI missing field detection failed', ['error' => $e->getMessage()]);
        }

        // Fallback: check required fields
        $missing = [];
        foreach ($fieldDefinitions as $name => $def) {
            if (($def['required'] ?? false) && empty($collectedData[$name])) {
                $missing[] = $name;
            }
        }
        return $missing;
    }
}
