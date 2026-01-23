<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Log;

/**
 * Intelligent Prompt Generator
 *
 * Uses intent analysis to generate contextual, intelligent prompts
 * that guide the AI to make better decisions and extractions
 */
class IntelligentPromptGenerator
{
    public function __construct(
        protected AIEngineService $ai,
        protected IntentAnalysisService $intentAnalysis
    ) {}

    /**
     * Generate intelligent prompt based on context (NO AI CALL)
     * Intent is inferred from context, not analyzed separately
     */
    public function generatePrompt(
        string $userMessage,
        UnifiedActionContext $context,
        array $options = []
    ): string {
        // Infer intent from context (no AI call needed)
        $intent = $this->inferIntentFromContext($context, $userMessage);

        // Build prompt directly - single step
        return $this->buildContextualPrompt($intent, $context, $userMessage, $options);
    }

    /**
     * Infer intent from context without AI call (language-agnostic)
     */
    protected function inferIntentFromContext(UnifiedActionContext $context, string $message): string
    {
        // PRIORITY 1: Context-based inference (works in ANY language)

        // If we're asking for specific field, user is providing data
        if ($context->get('asking_for')) {
            return 'provide_data';
        }

        // If we're waiting for confirmation, analyze the response
        if ($context->get('awaiting_confirmation')) {
            $messageLength = mb_strlen(trim($message));

            // Very short messages (1-5 chars) are almost always yes/no
            // Works for: "yes", "ok", "да", "نعم", "是", "oui", "si", "ja"
            if ($messageLength <= 5) {
                return 'confirm';
            }

            // Medium length (6-15 chars) - could be confirmation or correction
            // Let AI in the extraction step handle the nuance
            if ($messageLength <= 15) {
                // Check if it looks like a single word (no spaces)
                if (strpos(trim($message), ' ') === false) {
                    return 'confirm'; // Single word = likely yes/no
                }
            }

            // Longer messages = providing corrections/changes
            return 'provide_data';
        }

        // If we have active workflow and current step, user is providing data
        if (!empty($context->currentWorkflow) && !empty($context->currentStep)) {
            return 'provide_data';
        }

        // PRIORITY 2: Message pattern analysis (language-agnostic)

        // Very short messages in workflow context are likely confirmations
        if (!empty($context->currentWorkflow)) {
            $messageLength = mb_strlen(trim($message));
            if ($messageLength <= 5) {
                return 'confirm'; // "yes", "ok", "да", "نعم", etc.
            }
            return 'provide_data';
        }

        // PRIORITY 3: Default based on context state

        // No active workflow = likely starting new action
        if (empty($context->currentWorkflow)) {
            return 'create';
        }

        // Default to provide_data (safest assumption in workflow)
        return 'provide_data';
    }

    /**
     * Build contextual prompt in one step (simplified)
     */
    protected function buildContextualPrompt(
        string $intent,
        UnifiedActionContext $context,
        string $message,
        array $options
    ): string {
        $askingFor = $context->get('asking_for');
        $collectedData = $context->get('collected_data', []);

        $prompt = "CONTEXT:\n";
        $prompt .= "- User Intent: {$intent}\n";
        $prompt .= "- Message: \"{$message}\"\n";

        if ($askingFor) {
            $prompt .= "- Currently asking for: '{$askingFor}'\n";
        }

        if (!empty($collectedData)) {
            $prompt .= "- Already collected: " . implode(', ', array_keys($collectedData)) . "\n";
        }

        $prompt .= "\nRULES:\n";

        // Intent-specific rules (simplified)
        switch ($intent) {
            case 'provide_data':
                $prompt .= "- Extract EXACTLY what was asked for\n";
                $prompt .= "- Keep COMPLETE values (e.g., 'Macbook Pro M99' stays as-is)\n";
                $prompt .= "- Model numbers (M1, M99, etc.) are PART OF NAME, NOT quantities\n";
                $prompt .= "- Quantity needs explicit number: '2 items', '3 products'\n";
                if ($askingFor) {
                    $prompt .= "- Extract into '{$askingFor}' field ONLY\n";
                }
                break;

            case 'create':
                $prompt .= "- Extract ALL relevant information\n";
                $prompt .= "- Don't ask for what's already provided\n";
                break;

            case 'update':
                $prompt .= "- Extract identifier and new values\n";
                break;
        }

        return $prompt;
    }

    /**
     * Generate next question intelligently based on context
     */
    public function generateNextQuestion(
        UnifiedActionContext $context,
        array $missingFields,
        array $fieldDefinitions
    ): string {
        if (empty($missingFields)) {
            return "All information collected.";
        }

        $nextField = $missingFields[0];
        $fieldDef = $fieldDefinitions[$nextField] ?? [];

        // Use friendlyName if available (especially for entity fields like category_id → "category name")
        $displayName = $fieldDef['friendly_name'] ?? $nextField;

        // If a custom prompt is provided, use it directly (highest priority)
        if (!empty($fieldDef['prompt'])) {
            return $fieldDef['prompt'];
        }

        // Use AI to generate contextual question with validation awareness
        $prompt = "Generate a natural, conversational question to ask the user.\n\n";
        $prompt .= "CONTEXT:\n";
        $prompt .= "- We're collecting: {$displayName}\n";
        $prompt .= "- Field type: " . ($fieldDef['type'] ?? 'string') . "\n";
        $prompt .= "- Description: " . ($fieldDef['description'] ?? $displayName) . "\n";

        // Add required/optional status
        $isRequired = $fieldDef['required'] ?? true;
        $prompt .= "- Required: " . ($isRequired ? 'yes' : 'no (optional)') . "\n";

        // Add examples if provided
        if (!empty($fieldDef['examples'])) {
            $examples = is_array($fieldDef['examples']) ? implode(', ', $fieldDef['examples']) : $fieldDef['examples'];
            $prompt .= "- Examples: {$examples}\n";
        }

        // Add validation requirements for AI to understand
        if (!empty($fieldDef['validation'])) {
            $validationRules = is_array($fieldDef['validation']) ? $fieldDef['validation'] : explode('|', $fieldDef['validation']);
            $prompt .= "- Validation requirements:\n";
            foreach ($validationRules as $rule) {
                $rule = trim($rule);
                if (str_contains($rule, 'min:')) {
                    $prompt .= "  * Minimum " . str_replace('min:', '', $rule) . " characters\n";
                }
                if (str_contains($rule, 'max:')) {
                    $prompt .= "  * Maximum " . str_replace('max:', '', $rule) . " characters\n";
                }
                if ($rule === 'email') {
                    $prompt .= "  * Must be a valid email address format\n";
                }
                if ($rule === 'numeric') {
                    $prompt .= "  * Must be a number\n";
                }
                if ($rule === 'url') {
                    $prompt .= "  * Must be a valid URL\n";
                }
            }
        }

        $collectedData = $context->get('collected_data', []);
        if (!empty($collectedData)) {
            $prompt .= "- Already collected: " . json_encode($collectedData, JSON_PRETTY_PRINT) . "\n";
        }

        $prompt .= "\nGENERATE:\n";
        $prompt .= "A friendly, natural question that:\n";
        $prompt .= "1. Asks for the {$displayName}\n";
        $prompt .= "2. References already collected data if relevant\n";
        $prompt .= "3. Includes examples if provided above\n";
        $prompt .= "4. Mentions validation requirements naturally (e.g., 'valid email address')\n";
        $prompt .= "5. Mentions if field is optional\n";
        $prompt .= "6. Is conversational and not robotic\n\n";
        $prompt .= "IMPORTANT: You will validate the user's response in the next turn. If they provide invalid data, politely explain what's wrong and ask them to try again.\n\n";
        $prompt .= "Return ONLY the question, no explanation.";

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            return trim($response->getContent());
        } catch (\Exception $e) {
            // Fallback to simple question using friendly name
            return $fieldDef['prompt'] ?? "Please provide {$displayName}";
        }
    }
}
