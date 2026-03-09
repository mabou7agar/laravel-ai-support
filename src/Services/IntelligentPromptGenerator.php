<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
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
        protected IntentAnalysisService $intentAnalysis,
        protected ?LocaleResourceService $localeResources = null
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

            // Very short messages (1-5 chars) are almost always confirmation/rejection
            // Works for: "yes", "ok", "да", "نعم", "是", "oui", "si", "ja"
            if ($messageLength <= 5) {
                return 'confirm';
            }

            // Medium length (6-15 chars) - could be confirmation or correction
            // Let AI in the extraction step handle the nuance
            if ($messageLength <= 15) {
                // Check if it looks like a single word (no spaces)
                if (strpos(trim($message), ' ') === false) {
                    return 'confirm'; // Single word = likely a confirmation signal
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
        $intentRules = $this->intentRulesText($intent, $askingFor);

        return $this->renderPromptTemplate(
            'intelligent_prompt/contextual_prompt',
            [
                'intent' => $intent,
                'message' => $message,
                'asking_for' => $askingFor ?? '',
                'collected_keys' => !empty($collectedData) ? implode(', ', array_keys($collectedData)) : '',
                'intent_rules' => $intentRules,
            ],
            $this->buildContextualPromptFallback($intent, $message, $askingFor, $collectedData, $intentRules)
        );
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
            return $this->runtimeText(
                'ai-engine::runtime.intelligent_prompt.all_information_collected',
                'All information collected.'
            );
        }

        $nextField = $missingFields[0];
        $fieldDef = $fieldDefinitions[$nextField] ?? [];

        // Use friendlyName if available (especially for entity fields like category_id → "category name")
        $displayName = $fieldDef['friendly_name'] ?? $nextField;

        // If a custom prompt is provided, use it directly (highest priority)
        if (!empty($fieldDef['prompt'])) {
            return $fieldDef['prompt'];
        }

        $isRequired = $fieldDef['required'] ?? true;
        $requiredLabel = $isRequired
            ? $this->runtimeText('ai-engine::runtime.intelligent_prompt.required_label')
            : $this->runtimeText('ai-engine::runtime.intelligent_prompt.optional_label');
        $examples = '';
        if (!empty($fieldDef['examples'])) {
            $examples = is_array($fieldDef['examples']) ? implode(', ', $fieldDef['examples']) : (string) $fieldDef['examples'];
        }
        $validationRequirements = $this->validationRequirementsText($fieldDef['validation'] ?? null);
        $collectedData = $context->get('collected_data', []);

        $prompt = $this->renderPromptTemplate(
            'intelligent_prompt/next_question',
            [
                'display_name' => $displayName,
                'field_type' => (string) ($fieldDef['type'] ?? 'string'),
                'description' => (string) ($fieldDef['description'] ?? $displayName),
                'required_label' => $requiredLabel,
                'examples' => $examples,
                'validation_requirements' => $validationRequirements,
                'collected_data_json' => !empty($collectedData)
                    ? json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    : '',
            ],
            $this->buildNextQuestionFallbackPrompt(
                $displayName,
                $fieldDef,
                $isRequired,
                $examples,
                $validationRequirements,
                $collectedData
            )
        );

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            $question = trim((string) $response->getContent());
            if ($question !== '') {
                return $question;
            }

            Log::channel('ai-engine')->warning('AI returned empty question, using fallback prompt', [
                'field' => $nextField,
                'display_name' => $displayName,
            ]);

            return $fieldDef['prompt'] ?? $this->runtimeText(
                'ai-engine::runtime.intelligent_prompt.provide_field',
                '',
                ['field' => $displayName]
            );
        } catch (\Exception $e) {
            // Fallback to simple question using friendly name
            return $fieldDef['prompt'] ?? $this->runtimeText(
                'ai-engine::runtime.intelligent_prompt.provide_field',
                '',
                ['field' => $displayName]
            );
        }
    }

    protected function intentRulesText(string $intent, ?string $askingFor): string
    {
        $lines = match ($intent) {
            'provide_data' => [
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.provide_data.extract_requested'),
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.provide_data.keep_complete_values'),
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.provide_data.model_number_part_name'),
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.provide_data.quantity_explicit'),
            ],
            'create' => [
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.create.extract_all_relevant'),
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.create.do_not_ask_existing'),
            ],
            'update' => [
                $this->runtimeText('ai-engine::runtime.intelligent_prompt.rules.update.extract_identifier_values'),
            ],
            default => [],
        };

        if ($intent === 'provide_data' && $askingFor) {
            $lines[] = $this->runtimeText(
                'ai-engine::runtime.intelligent_prompt.rules.provide_data.extract_into_field',
                '',
                ['field' => $askingFor]
            );
        }

        $lines = array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        if ($lines === []) {
            return '';
        }

        return '- ' . implode("\n- ", $lines);
    }

    protected function validationRequirementsText(mixed $validation): string
    {
        if (empty($validation)) {
            return '';
        }

        $lines = [];
        $validationRules = is_array($validation) ? $validation : explode('|', (string) $validation);
        foreach ($validationRules as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if (str_contains($rule, 'min:')) {
                $lines[] = $this->runtimeText(
                    'ai-engine::runtime.intelligent_prompt.validation.minimum_characters',
                    '',
                    ['value' => str_replace('min:', '', $rule)]
                );
            }
            if (str_contains($rule, 'max:')) {
                $lines[] = $this->runtimeText(
                    'ai-engine::runtime.intelligent_prompt.validation.maximum_characters',
                    '',
                    ['value' => str_replace('max:', '', $rule)]
                );
            }
            if ($rule === 'email') {
                $lines[] = $this->runtimeText('ai-engine::runtime.intelligent_prompt.validation.valid_email');
            }
            if ($rule === 'numeric') {
                $lines[] = $this->runtimeText('ai-engine::runtime.intelligent_prompt.validation.numeric');
            }
            if ($rule === 'url') {
                $lines[] = $this->runtimeText('ai-engine::runtime.intelligent_prompt.validation.valid_url');
            }
        }

        $lines = array_values(array_filter(
            array_map(static fn (mixed $line): string => trim((string) $line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        if ($lines === []) {
            return '';
        }

        return '* ' . implode("\n* ", $lines);
    }

    protected function renderPromptTemplate(string $template, array $replace, string $fallback): string
    {
        $rendered = $this->locale()->renderPromptTemplate($template, $replace, $this->localeCode());
        return $rendered !== '' ? $rendered : $fallback;
    }

    protected function localeCode(): string
    {
        return $this->locale()->resolveLocale(app()->getLocale());
    }

    protected function runtimeText(string $key, string $fallback = '', array $replace = []): string
    {
        $locale = $this->localeCode();
        $translated = $this->locale()->translation($key, $replace, $locale);
        if ($translated !== '') {
            return $translated;
        }

        $fallbackLocale = $this->locale()->resolveLocale(
            (string) (config('ai-engine.localization.fallback_locale') ?: config('app.fallback_locale') ?: app()->getLocale())
        );
        if ($fallbackLocale !== $locale) {
            $translated = $this->locale()->translation($key, $replace, $fallbackLocale);
            if ($translated !== '') {
                return $translated;
            }
        }

        $fallbackReplace = [];
        foreach ($replace as $name => $value) {
            $fallbackReplace[':' . $name] = (string) $value;
        }

        return $fallback !== '' ? strtr($fallback, $fallbackReplace) : '';
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    protected function buildContextualPromptFallback(
        string $intent,
        string $message,
        ?string $askingFor,
        array $collectedData,
        string $intentRules
    ): string {
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
        if ($intentRules !== '') {
            $prompt .= $intentRules . "\n";
        }

        return $prompt;
    }

    protected function buildNextQuestionFallbackPrompt(
        string $displayName,
        array $fieldDef,
        bool $isRequired,
        string $examples,
        string $validationRequirements,
        array $collectedData
    ): string {
        $prompt = "Generate a natural, conversational question to ask the user.\n\n";
        $prompt .= "CONTEXT:\n";
        $prompt .= "- We're collecting: {$displayName}\n";
        $prompt .= "- Field type: " . ($fieldDef['type'] ?? 'string') . "\n";
        $prompt .= "- Description: " . ($fieldDef['description'] ?? $displayName) . "\n";
        $prompt .= "- Required: " . ($isRequired
            ? $this->runtimeText('ai-engine::runtime.intelligent_prompt.required_label')
            : $this->runtimeText('ai-engine::runtime.intelligent_prompt.optional_label')) . "\n";
        if ($examples !== '') {
            $prompt .= "- Examples: {$examples}\n";
        }
        if ($validationRequirements !== '') {
            $prompt .= "- Validation requirements:\n{$validationRequirements}\n";
        }
        if (!empty($collectedData)) {
            $prompt .= "- Already collected: " . json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }

        $prompt .= "\nGENERATE:\n";
        $prompt .= "A friendly, natural question that:\n";
        $prompt .= "1. Asks for the {$displayName}\n";
        $prompt .= "2. References already collected data if relevant\n";
        $prompt .= "3. Includes examples if provided above\n";
        $prompt .= "4. Mentions validation requirements naturally\n";
        $prompt .= "5. Mentions if field is optional\n";
        $prompt .= "6. Is conversational and not robotic\n\n";
        $prompt .= "IMPORTANT: You will validate the user's response in the next turn. If invalid, explain and ask to try again.\n\n";
        $prompt .= "Return ONLY the question, no explanation.";

        return $prompt;
    }
}
