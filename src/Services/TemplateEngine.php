<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class TemplateEngine
{
    private array $defaultTemplates;

    public function __construct(
        protected ?AIEngineService $aiService = null
    ) {
        $this->defaultTemplates = $this->loadDefaultTemplates();
    }

    /**
     * Get all available templates
     */
    public function getAllTemplates(): array
    {
        $customTemplates = Cache::get('ai_custom_templates', []);
        return array_merge($this->defaultTemplates, $customTemplates);
    }

    /**
     * Get template by ID
     */
    public function getTemplate(string $templateId): ?array
    {
        $templates = $this->getAllTemplates();
        return $templates[$templateId] ?? null;
    }

    /**
     * Get templates by category
     */
    public function getTemplatesByCategory(string $category): array
    {
        return array_filter($this->getAllTemplates(), function ($template) use ($category) {
            return ($template['category'] ?? 'general') === $category;
        });
    }

    /**
     * Get available categories
     */
    public function getCategories(): array
    {
        $categories = [];
        foreach ($this->getAllTemplates() as $template) {
            $category = $template['category'] ?? 'general';
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }
        }
        return $categories;
    }

    /**
     * Create custom template
     */
    public function createTemplate(array $templateData): array
    {
        $template = [
            'id' => $templateData['id'] ?? 'custom_' . uniqid(),
            'name' => $templateData['name'],
            'description' => $templateData['description'] ?? '',
            'category' => $templateData['category'] ?? 'custom',
            'system_prompt' => $templateData['system_prompt'] ?? '',
            'user_prompt' => $templateData['user_prompt'] ?? '',
            'variables' => $templateData['variables'] ?? [],
            'default_engine' => $templateData['default_engine'] ?? 'openai',
            'default_model' => $templateData['default_model'] ?? 'gpt-4o',
            'default_parameters' => $templateData['default_parameters'] ?? [],
            'is_custom' => true,
            'created_at' => now()->toIso8601String(),
        ];

        $customTemplates = Cache::get('ai_custom_templates', []);
        $customTemplates[$template['id']] = $template;
        Cache::put('ai_custom_templates', $customTemplates, now()->addYear());

        return $template;
    }

    /**
     * Update custom template
     */
    public function updateTemplate(string $templateId, array $templateData): ?array
    {
        $customTemplates = Cache::get('ai_custom_templates', []);
        
        if (!isset($customTemplates[$templateId])) {
            return null;
        }

        $customTemplates[$templateId] = array_merge($customTemplates[$templateId], $templateData, [
            'updated_at' => now()->toIso8601String(),
        ]);
        
        Cache::put('ai_custom_templates', $customTemplates, now()->addYear());

        return $customTemplates[$templateId];
    }

    /**
     * Delete custom template
     */
    public function deleteTemplate(string $templateId): bool
    {
        $customTemplates = Cache::get('ai_custom_templates', []);
        
        if (!isset($customTemplates[$templateId])) {
            return false;
        }

        unset($customTemplates[$templateId]);
        Cache::put('ai_custom_templates', $customTemplates, now()->addYear());

        return true;
    }

    /**
     * Execute a template with variables
     */
    public function execute(string $templateId, array $variables = [], array $options = []): ?AIResponse
    {
        $template = $this->getTemplate($templateId);
        
        if (!$template) {
            return null;
        }

        // Build the prompt with variable substitution
        $systemPrompt = $this->substituteVariables($template['system_prompt'] ?? '', $variables);
        $userPrompt = $this->substituteVariables($template['user_prompt'] ?? '', $variables);

        // Get engine and model
        $engine = $options['engine'] ?? $template['default_engine'] ?? 'openai';
        $model = $options['model'] ?? $template['default_model'] ?? 'gpt-4o';
        $parameters = array_merge($template['default_parameters'] ?? [], $options['parameters'] ?? []);

        if (!$this->aiService) {
            return null;
        }

        try {
            $request = new AIRequest(
                prompt: $userPrompt,
                engine: EngineEnum::fromSlug($engine),
                model: EntityEnum::fromSlug($model),
                parameters: array_merge($parameters, [
                    'system_prompt' => $systemPrompt,
                ])
            );

            return $this->aiService->generate($request);
        } catch (\Exception $e) {
            return AIResponse::error($e->getMessage(), EngineEnum::fromSlug($engine), EntityEnum::fromSlug($model));
        }
    }

    /**
     * Substitute variables in a string
     */
    protected function substituteVariables(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $text = str_replace('{{' . $key . '}}', (string) $value, $text);
                $text = str_replace('{{ ' . $key . ' }}', (string) $value, $text);
            }
        }
        return $text;
    }

    /**
     * Load default templates
     */
    protected function loadDefaultTemplates(): array
    {
        return [
            // Writing Templates
            'summarize' => [
                'id' => 'summarize',
                'name' => 'Summarize Content',
                'description' => 'Create a concise summary of the provided content',
                'category' => 'writing',
                'system_prompt' => 'You are an expert at creating clear, concise summaries. Focus on the key points and main ideas.',
                'user_prompt' => "Please summarize the following content:\n\n{{content}}\n\nProvide a {{length}} summary.",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to summarize', 'required' => true],
                    ['name' => 'length', 'description' => 'Summary length (brief, detailed, comprehensive)', 'required' => false, 'default' => 'brief'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o-mini',
                'default_parameters' => ['max_tokens' => 500],
            ],

            'rewrite' => [
                'id' => 'rewrite',
                'name' => 'Rewrite Content',
                'description' => 'Rewrite content in a different tone or style',
                'category' => 'writing',
                'system_prompt' => 'You are an expert writer who can adapt content to different tones and styles while preserving the original meaning.',
                'user_prompt' => "Rewrite the following content in a {{tone}} tone:\n\n{{content}}",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to rewrite', 'required' => true],
                    ['name' => 'tone', 'description' => 'Target tone (professional, casual, formal, friendly)', 'required' => false, 'default' => 'professional'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 1000],
            ],

            'expand' => [
                'id' => 'expand',
                'name' => 'Expand Content',
                'description' => 'Expand brief content into more detailed text',
                'category' => 'writing',
                'system_prompt' => 'You are an expert at expanding brief content into detailed, well-structured text while maintaining the original intent.',
                'user_prompt' => "Expand the following content into a more detailed version:\n\n{{content}}\n\nTarget length: {{target_length}}",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to expand', 'required' => true],
                    ['name' => 'target_length', 'description' => 'Target length (paragraph, page, article)', 'required' => false, 'default' => 'paragraph'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 2000],
            ],

            // Translation Templates
            'translate' => [
                'id' => 'translate',
                'name' => 'Translate Content',
                'description' => 'Translate content to another language',
                'category' => 'translation',
                'system_prompt' => 'You are an expert translator. Provide accurate, natural-sounding translations that preserve the original meaning and tone.',
                'user_prompt' => "Translate the following content to {{target_language}}:\n\n{{content}}",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to translate', 'required' => true],
                    ['name' => 'target_language', 'description' => 'Target language', 'required' => true],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 2000],
            ],

            // Code Templates
            'code_review' => [
                'id' => 'code_review',
                'name' => 'Code Review',
                'description' => 'Review code for issues, improvements, and best practices',
                'category' => 'coding',
                'system_prompt' => 'You are an expert code reviewer. Analyze code for bugs, security issues, performance problems, and suggest improvements following best practices.',
                'user_prompt' => "Review the following {{language}} code:\n\n```{{language}}\n{{code}}\n```\n\nProvide feedback on:\n1. Potential bugs\n2. Security issues\n3. Performance improvements\n4. Code style and best practices",
                'variables' => [
                    ['name' => 'code', 'description' => 'The code to review', 'required' => true],
                    ['name' => 'language', 'description' => 'Programming language', 'required' => false, 'default' => 'php'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 2000],
            ],

            'code_explain' => [
                'id' => 'code_explain',
                'name' => 'Explain Code',
                'description' => 'Explain what code does in plain language',
                'category' => 'coding',
                'system_prompt' => 'You are an expert at explaining code in clear, simple terms. Break down complex logic into understandable explanations.',
                'user_prompt' => "Explain what this {{language}} code does:\n\n```{{language}}\n{{code}}\n```\n\nExplanation level: {{level}}",
                'variables' => [
                    ['name' => 'code', 'description' => 'The code to explain', 'required' => true],
                    ['name' => 'language', 'description' => 'Programming language', 'required' => false, 'default' => 'php'],
                    ['name' => 'level', 'description' => 'Explanation level (beginner, intermediate, advanced)', 'required' => false, 'default' => 'intermediate'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 1500],
            ],

            'code_generate' => [
                'id' => 'code_generate',
                'name' => 'Generate Code',
                'description' => 'Generate code based on requirements',
                'category' => 'coding',
                'system_prompt' => 'You are an expert programmer. Generate clean, well-documented, production-ready code following best practices.',
                'user_prompt' => "Generate {{language}} code for the following requirement:\n\n{{requirement}}\n\nInclude comments and follow best practices.",
                'variables' => [
                    ['name' => 'requirement', 'description' => 'What the code should do', 'required' => true],
                    ['name' => 'language', 'description' => 'Programming language', 'required' => false, 'default' => 'php'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 3000],
            ],

            // Analysis Templates
            'sentiment' => [
                'id' => 'sentiment',
                'name' => 'Sentiment Analysis',
                'description' => 'Analyze the sentiment of content',
                'category' => 'analysis',
                'system_prompt' => 'You are an expert at sentiment analysis. Analyze text for emotional tone and provide detailed insights.',
                'user_prompt' => "Analyze the sentiment of the following content:\n\n{{content}}\n\nProvide:\n1. Overall sentiment (positive, negative, neutral)\n2. Confidence score (0-100%)\n3. Key emotional indicators\n4. Brief explanation",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to analyze', 'required' => true],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o-mini',
                'default_parameters' => ['max_tokens' => 500],
            ],

            'extract_entities' => [
                'id' => 'extract_entities',
                'name' => 'Extract Entities',
                'description' => 'Extract named entities from content',
                'category' => 'analysis',
                'system_prompt' => 'You are an expert at named entity recognition. Extract and categorize all entities from the provided text.',
                'user_prompt' => "Extract all named entities from the following content:\n\n{{content}}\n\nCategorize them as:\n- People\n- Organizations\n- Locations\n- Dates/Times\n- Products\n- Other",
                'variables' => [
                    ['name' => 'content', 'description' => 'The content to analyze', 'required' => true],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o-mini',
                'default_parameters' => ['max_tokens' => 1000],
            ],

            // Email Templates
            'email_reply' => [
                'id' => 'email_reply',
                'name' => 'Draft Email Reply',
                'description' => 'Generate a professional email reply',
                'category' => 'email',
                'system_prompt' => 'You are an expert at writing professional emails. Create clear, concise, and appropriate email responses.',
                'user_prompt' => "Draft a {{tone}} reply to this email:\n\nOriginal email:\n{{original_email}}\n\nKey points to address:\n{{key_points}}",
                'variables' => [
                    ['name' => 'original_email', 'description' => 'The email to reply to', 'required' => true],
                    ['name' => 'key_points', 'description' => 'Key points to include in reply', 'required' => false, 'default' => ''],
                    ['name' => 'tone', 'description' => 'Email tone (professional, friendly, formal)', 'required' => false, 'default' => 'professional'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 1000],
            ],

            'email_compose' => [
                'id' => 'email_compose',
                'name' => 'Compose Email',
                'description' => 'Compose a new email from scratch',
                'category' => 'email',
                'system_prompt' => 'You are an expert at writing professional emails. Create clear, well-structured emails appropriate for the context.',
                'user_prompt' => "Compose a {{tone}} email about:\n\nSubject: {{subject}}\nPurpose: {{purpose}}\nRecipient context: {{recipient}}",
                'variables' => [
                    ['name' => 'subject', 'description' => 'Email subject', 'required' => true],
                    ['name' => 'purpose', 'description' => 'Purpose of the email', 'required' => true],
                    ['name' => 'recipient', 'description' => 'Who is receiving this email', 'required' => false, 'default' => 'colleague'],
                    ['name' => 'tone', 'description' => 'Email tone', 'required' => false, 'default' => 'professional'],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 1000],
            ],

            // Data Templates
            'json_generate' => [
                'id' => 'json_generate',
                'name' => 'Generate JSON',
                'description' => 'Generate structured JSON data',
                'category' => 'data',
                'system_prompt' => 'You are an expert at generating structured JSON data. Always return valid JSON without markdown code blocks.',
                'user_prompt' => "Generate JSON data based on this schema:\n\n{{schema}}\n\nContext/Requirements:\n{{requirements}}",
                'variables' => [
                    ['name' => 'schema', 'description' => 'JSON schema or structure description', 'required' => true],
                    ['name' => 'requirements', 'description' => 'Additional requirements or context', 'required' => false, 'default' => ''],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 2000],
            ],

            'data_transform' => [
                'id' => 'data_transform',
                'name' => 'Transform Data',
                'description' => 'Transform data from one format to another',
                'category' => 'data',
                'system_prompt' => 'You are an expert at data transformation. Convert data between formats accurately while preserving all information.',
                'user_prompt' => "Transform this data from {{source_format}} to {{target_format}}:\n\n{{data}}",
                'variables' => [
                    ['name' => 'data', 'description' => 'The data to transform', 'required' => true],
                    ['name' => 'source_format', 'description' => 'Source format (JSON, XML, CSV, etc.)', 'required' => true],
                    ['name' => 'target_format', 'description' => 'Target format', 'required' => true],
                ],
                'default_engine' => 'openai',
                'default_model' => 'gpt-4o',
                'default_parameters' => ['max_tokens' => 3000],
            ],
        ];
    }

    /**
     * Search templates by name or description
     */
    public function searchTemplates(string $query): array
    {
        $query = strtolower($query);
        return array_filter($this->getAllTemplates(), function ($template) use ($query) {
            return str_contains(strtolower($template['name'] ?? ''), $query) ||
                   str_contains(strtolower($template['description'] ?? ''), $query);
        });
    }

    /**
     * Validate variables for a template
     */
    public function validateVariables(string $templateId, array $variables): array
    {
        $template = $this->getTemplate($templateId);
        
        if (!$template) {
            return ['error' => 'Template not found'];
        }

        $errors = [];
        foreach ($template['variables'] ?? [] as $varDef) {
            $name = $varDef['name'];
            $required = $varDef['required'] ?? false;
            
            if ($required && (!isset($variables[$name]) || empty($variables[$name]))) {
                $errors[$name] = "Variable '{$name}' is required";
            }
        }

        return $errors;
    }

    /**
     * Get template with default values filled in
     */
    public function getTemplateWithDefaults(string $templateId): ?array
    {
        $template = $this->getTemplate($templateId);
        
        if (!$template) {
            return null;
        }

        $defaults = [];
        foreach ($template['variables'] ?? [] as $varDef) {
            if (isset($varDef['default'])) {
                $defaults[$varDef['name']] = $varDef['default'];
            }
        }

        $template['default_values'] = $defaults;
        return $template;
    }
}
