<?php

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
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
    protected ?LocaleResourceService $localeResources = null;

    public function __construct(AIEngineService $ai, ?LocaleResourceService $localeResources = null)
    {
        $this->ai = $ai;
        $this->localeResources = $localeResources;
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
            $contextJson = !empty($context) ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/validate_field',
                [
                    'field_name' => $fieldName,
                    'field_description' => (string) ($fieldDefinition['description']
                        ?? $this->runtimeText('ai-engine::runtime.ai_enhanced_workflow.not_available')),
                    'field_type' => (string) ($fieldDefinition['type'] ?? 'string'),
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'context_json' => $contextJson,
                    'additional_rules' => (string) ($fieldDefinition['validation'] ?? ''),
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.validate_field',
                    [
                        'field_name' => $fieldName,
                        'field_type' => (string) ($fieldDefinition['type'] ?? 'string'),
                        'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->getContent(), true);

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

            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/find_matching_entity',
                [
                    'user_query' => $userQuery,
                    'samples_json' => json_encode($samples, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'conversation_context_json' => !empty($conversationContext)
                        ? json_encode($conversationContext, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : '',
                    'limit' => (string) $limit,
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.find_matching_entity',
                    [
                        'user_query' => $userQuery,
                        'samples_json' => json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'limit' => (string) $limit,
                    ]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 500,
                temperature: 0.3
            ));

            $matches = json_decode($response->getContent(), true);

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
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/detect_duplicate',
                [
                    'new_data_json' => json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'existing_records_json' => json_encode($existingRecords, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'entity_type' => $entityType,
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.detect_duplicate',
                    [
                        'new_data_json' => json_encode($newData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'existing_records_json' => json_encode($existingRecords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'entity_type' => $entityType,
                    ]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $result = json_decode($response->getContent(), true);

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
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/generate_field_prompt',
                [
                    'field_name' => $fieldName,
                    'field_description' => (string) ($fieldDefinition['description']
                        ?? $this->runtimeText('ai-engine::runtime.ai_enhanced_workflow.not_available')),
                    'field_type' => (string) ($fieldDefinition['type'] ?? 'string'),
                    'required' => ($fieldDefinition['required'] ?? false)
                        ? $this->runtimeText('ai-engine::runtime.ai_enhanced_workflow.required_label')
                        : $this->runtimeText('ai-engine::runtime.ai_enhanced_workflow.optional_label'),
                    'collected_data_json' => !empty($collectedData)
                        ? json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : '',
                    'recent_history_text' => $this->recentHistoryText($conversationHistory),
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.generate_field_prompt',
                    ['field_name' => $fieldName]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            return trim($response->getContent());

        } catch (\Exception $e) {
            Log::error('AI prompt generation failed', ['error' => $e->getMessage()]);
        }

        // Fallback
        return $fieldDefinition['prompt']
            ?? $this->runtimeText(
                'ai-engine::runtime.ai_enhanced_workflow.default_field_prompt',
                ['field_name' => $fieldName]
            );
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
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/generate_error_message',
                [
                    'field_name' => $fieldName,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'validation_error' => $validationError,
                    'context_json' => !empty($context)
                        ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        : '',
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.generate_error_message',
                    ['field_name' => $fieldName, 'validation_error' => $validationError]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 100,
                temperature: 0.7
            ));

            return trim($response->getContent());

        } catch (\Exception $e) {
            Log::error('AI error message generation failed', ['error' => $e->getMessage()]);
        }

        // Fallback
        return $this->runtimeText(
            'ai-engine::runtime.ai_enhanced_workflow.default_error_message',
            ['field_name' => $fieldName, 'validation_error' => $validationError]
        );
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
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/infer_field_type',
                [
                    'field_name' => $fieldName,
                    'description' => $description ?? '',
                    'sample_data_json' => !empty($sampleData)
                        ? json_encode($sampleData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : '',
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.infer_field_type',
                    ['field_name' => $fieldName]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 20,
                temperature: 0
            ));

            $type = strtolower(trim($response->getContent()));

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
            $prompt = $this->renderPromptTemplate(
                'ai_enhanced_workflow/missing_fields',
                [
                    'goal' => $goal,
                    'collected_data_json' => json_encode($collectedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    'fields_list' => $this->fieldDefinitionsList($fieldDefinitions),
                    'recent_history_text' => $this->recentHistoryText($conversationHistory),
                ],
                $this->runtimeText(
                    'ai-engine::runtime.ai_enhanced_workflow.prompts.missing_fields',
                    [
                        'goal' => $goal,
                        'collected_data_json' => json_encode($collectedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'fields_list' => $this->fieldDefinitionsList($fieldDefinitions),
                    ]
                )
            );

            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from('openai'),
                model: EntityEnum::from('gpt-4o-mini'),
                maxTokens: 200,
                temperature: 0
            ));

            $missing = json_decode($response->getContent(), true);

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

    protected function renderPromptTemplate(string $template, array $replace, string $fallback): string
    {
        $rendered = $this->locale()->renderPromptTemplate($template, $replace, $this->localeCode());
        return $rendered !== '' ? $rendered : $fallback;
    }

    protected function localeCode(): string
    {
        return $this->locale()->resolveLocale(app()->getLocale());
    }

    protected function locale(): LocaleResourceService
    {
        if ($this->localeResources === null) {
            $this->localeResources = app(LocaleResourceService::class);
        }

        return $this->localeResources;
    }

    protected function recentHistoryText(array $conversationHistory): string
    {
        if ($conversationHistory === []) {
            return '';
        }

        $lines = [];
        foreach (array_slice($conversationHistory, -3) as $msg) {
            $lines[] = ($msg['role'] ?? 'user') . ': ' . ($msg['content'] ?? '');
        }

        return implode("\n", $lines);
    }

    protected function fieldDefinitionsList(array $fieldDefinitions): string
    {
        $requiredSuffix = $this->runtimeText(
            'ai-engine::runtime.ai_enhanced_workflow.required_suffix'
        );
        $optionalSuffix = $this->runtimeText(
            'ai-engine::runtime.ai_enhanced_workflow.optional_suffix'
        );

        $lines = [];
        foreach ($fieldDefinitions as $name => $def) {
            $required = (bool) ($def['required'] ?? false);
            $desc = $def['description'] ?? '';
            $lines[] = "- {$name}" . ($required ? " {$requiredSuffix}" : " {$optionalSuffix}") . ": {$desc}";
        }

        return implode("\n", $lines);
    }

    protected function runtimeText(
        string $key,
        string $fallback = '',
        array $replace = []
    ): string
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

        if ($fallback === '') {
            return '';
        }

        $fallbackReplace = [];
        foreach ($replace as $name => $value) {
            $fallbackReplace[':' . $name] = (string) $value;
        }

        return strtr($fallback, $fallbackReplace);
    }
}
