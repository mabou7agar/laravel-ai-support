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

    public function __construct()
    {
        $this->defaultTemplates = $this->loadDefaultTemplates();
    }

    /**
     * Get all available templates
     */
    public function getAllTemplates(): array
    {
        $customTemplates = Cache::get('custom_templates', []);
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
     * Create custom template
     */
    public function createTemplate(array $templateData): array
    {
        $template = [
            'id' => $templateData['id'] ?? uniqid('template_'),
            'name' => $templateData['name'],
            'description' => $templateData['description'] ?? '',
            'category' => $templateData['category'] ?? 'custom',
            'system_prompt' => $templateData['system_prompt'] ?? '',
            'user_prompt_template' => $templateData['user_prompt_template'],
            'variables' => $templateData['variables'] ?? [],
            'engine' => $templateData['engine'] ?? EngineEnum::OPENAI->value,
            'model' => $templateData['model'] ?? EntityEnum::GPT_4O->value,
            'temperature' => $templateData['temperature'] ?? 0.7,
            'max_tokens' => $templateData['max_tokens'] ?? null,
            'tags' => $templateData['tags'] ?? [],
            'examples' => $templateData['examples'] ?? [],
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'is_custom' => true,
        ];

        $this->storeCustomTemplate($template);
        return $template;
    }

    /**
     * Generate content using template
     */
    public function generateFromTemplate(string $templateId, array $variables = [], array $options = []): AIResponse
    {
        $template = $this->getTemplate($templateId);
        
        if (!$template) {
            return AIResponse::error(
                "Template '{$templateId}' not found",
                EngineEnum::OPENAI,
                EntityEnum::GPT_4O
            );
        }

        // Process template variables
        $processedPrompt = $this->processTemplateVariables($template['user_prompt_template'], $variables);
        $processedSystemPrompt = $this->processTemplateVariables($template['system_prompt'], $variables);

        // Create AI request
        $request = new AIRequest(
            prompt: $processedPrompt,
            engine: EngineEnum::from($options['engine'] ?? $template['engine']),
            model: EntityEnum::from($options['model'] ?? $template['model']),
            systemPrompt: $processedSystemPrompt,
            temperature: $options['temperature'] ?? $template['temperature'],
            maxTokens: $options['max_tokens'] ?? $template['max_tokens'],
            parameters: array_merge($options['parameters'] ?? [], [
                'template_id' => $templateId,
                'template_used' => true,
            ])
        );

        // Generate content
        $aiEngine = app('ai-engine');
        $response = $aiEngine->generateText($request);

        if ($response->isSuccess()) {
            return $response->withDetailedUsage(array_merge(
                $response->detailedUsage ?? [],
                [
                    'template_id' => $templateId,
                    'template_name' => $template['name'],
                    'variables_used' => $variables,
                ]
            ));
        }

        return $response;
    }

    /**
     * Get templates by category
     */
    public function getTemplatesByCategory(string $category): array
    {
        $templates = $this->getAllTemplates();
        return array_filter($templates, fn($template) => $template['category'] === $category);
    }

    /**
     * Search templates
     */
    public function searchTemplates(string $query): array
    {
        $templates = $this->getAllTemplates();
        $query = strtolower($query);
        
        return array_filter($templates, function($template) use ($query) {
            return str_contains(strtolower($template['name']), $query) ||
                   str_contains(strtolower($template['description']), $query) ||
                   in_array($query, array_map('strtolower', $template['tags'] ?? []));
        });
    }

    /**
     * Update template
     */
    public function updateTemplate(string $templateId, array $updates): array
    {
        if (isset($this->defaultTemplates[$templateId])) {
            throw new \InvalidArgumentException('Cannot update default template');
        }

        $customTemplates = Cache::get('custom_templates', []);
        
        if (!isset($customTemplates[$templateId])) {
            throw new \InvalidArgumentException('Template not found');
        }

        $customTemplates[$templateId] = array_merge(
            $customTemplates[$templateId],
            $updates,
            ['updated_at' => now()->toISOString()]
        );

        Cache::put('custom_templates', $customTemplates, now()->addDays(30));
        
        return $customTemplates[$templateId];
    }

    /**
     * Delete template
     */
    public function deleteTemplate(string $templateId): bool
    {
        if (isset($this->defaultTemplates[$templateId])) {
            throw new \InvalidArgumentException('Cannot delete default template');
        }

        $customTemplates = Cache::get('custom_templates', []);
        
        if (isset($customTemplates[$templateId])) {
            unset($customTemplates[$templateId]);
            Cache::put('custom_templates', $customTemplates, now()->addDays(30));
            return true;
        }
        
        return false;
    }

    /**
     * Validate template variables
     */
    public function validateTemplateVariables(string $templateId, array $variables): array
    {
        $template = $this->getTemplate($templateId);
        
        if (!$template) {
            return ['valid' => false, 'error' => 'Template not found'];
        }

        $requiredVariables = $template['variables'] ?? [];
        $missingVariables = [];
        $invalidVariables = [];

        foreach ($requiredVariables as $variable) {
            $varName = $variable['name'];
            
            if (!isset($variables[$varName])) {
                if ($variable['required'] ?? true) {
                    $missingVariables[] = $varName;
                }
            } else {
                // Validate variable type if specified
                if (isset($variable['type'])) {
                    if (!$this->validateVariableType($variables[$varName], $variable['type'])) {
                        $invalidVariables[] = [
                            'name' => $varName,
                            'expected' => $variable['type'],
                            'actual' => gettype($variables[$varName]),
                        ];
                    }
                }
            }
        }

        return [
            'valid' => empty($missingVariables) && empty($invalidVariables),
            'missing_variables' => $missingVariables,
            'invalid_variables' => $invalidVariables,
        ];
    }

    /**
     * Get template suggestions based on content type
     */
    public function getTemplateSuggestions(string $contentType, string $industry = null): array
    {
        $templates = $this->getAllTemplates();
        
        $suggestions = array_filter($templates, function($template) use ($contentType, $industry) {
            $categoryMatch = $template['category'] === $contentType;
            $industryMatch = !$industry || in_array($industry, $template['tags'] ?? []);
            
            return $categoryMatch && $industryMatch;
        });

        // Sort by relevance (you could implement more sophisticated scoring)
        uasort($suggestions, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return array_values($suggestions);
    }

    /**
     * Process template variables in text
     */
    private function processTemplateVariables(string $text, array $variables): string
    {
        $processedText = $text;
        
        foreach ($variables as $key => $value) {
            $placeholders = [
                "{{$key}}",
                "{{{$key}}}",
                "[$key]",
                "%{$key}%",
            ];
            
            foreach ($placeholders as $placeholder) {
                $processedText = str_replace($placeholder, $value, $processedText);
            }
        }
        
        return $processedText;
    }

    /**
     * Validate variable type
     */
    private function validateVariableType($value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'number' => is_numeric($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'array' => is_array($value),
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($value, FILTER_VALIDATE_URL) !== false,
            default => true,
        };
    }

    /**
     * Store custom template
     */
    private function storeCustomTemplate(array $template): void
    {
        $customTemplates = Cache::get('custom_templates', []);
        $customTemplates[$template['id']] = $template;
        Cache::put('custom_templates', $customTemplates, now()->addDays(30));
    }

    /**
     * Load default templates
     */
    private function loadDefaultTemplates(): array
    {
        return [
            'blog_post' => [
                'id' => 'blog_post',
                'name' => 'Blog Post Writer',
                'description' => 'Create engaging blog posts with SEO optimization',
                'category' => 'content',
                'system_prompt' => 'You are an expert blog writer. Create engaging, SEO-optimized content that provides value to readers. Use clear headings, include relevant keywords naturally, and maintain a conversational yet professional tone.',
                'user_prompt_template' => 'Write a comprehensive blog post about {topic}. Target audience: {audience}. Desired length: {length} words. Include these keywords: {keywords}. Tone: {tone}.',
                'variables' => [
                    ['name' => 'topic', 'type' => 'string', 'required' => true, 'description' => 'Main topic of the blog post'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience'],
                    ['name' => 'length', 'type' => 'number', 'required' => false, 'default' => 1000, 'description' => 'Desired word count'],
                    ['name' => 'keywords', 'type' => 'string', 'required' => false, 'description' => 'SEO keywords to include'],
                    ['name' => 'tone', 'type' => 'string', 'required' => false, 'default' => 'professional', 'description' => 'Writing tone'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.7,
                'tags' => ['content', 'seo', 'marketing'],
                'examples' => [
                    [
                        'variables' => ['topic' => 'AI in Healthcare', 'audience' => 'healthcare professionals', 'length' => 1500],
                        'description' => 'Technical blog post for medical professionals'
                    ]
                ],
                'is_custom' => false,
            ],

            'social_media_post' => [
                'id' => 'social_media_post',
                'name' => 'Social Media Post',
                'description' => 'Create engaging social media content',
                'category' => 'social',
                'system_prompt' => 'You are a social media expert. Create engaging, shareable content that drives engagement. Use appropriate hashtags, emojis, and platform-specific best practices.',
                'user_prompt_template' => 'Create a {platform} post about {topic}. Target audience: {audience}. Tone: {tone}. Include relevant hashtags and call-to-action.',
                'variables' => [
                    ['name' => 'platform', 'type' => 'string', 'required' => true, 'description' => 'Social media platform (Twitter, LinkedIn, Instagram, etc.)'],
                    ['name' => 'topic', 'type' => 'string', 'required' => true, 'description' => 'Post topic or message'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience'],
                    ['name' => 'tone', 'type' => 'string', 'required' => false, 'default' => 'engaging', 'description' => 'Post tone'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O_MINI->value,
                'temperature' => 0.8,
                'tags' => ['social', 'marketing', 'engagement'],
                'is_custom' => false,
            ],

            'email_marketing' => [
                'id' => 'email_marketing',
                'name' => 'Email Marketing Campaign',
                'description' => 'Create compelling marketing emails',
                'category' => 'marketing',
                'system_prompt' => 'You are an email marketing expert. Create compelling emails that drive conversions. Use persuasive copy, clear CTAs, and personalization.',
                'user_prompt_template' => 'Create an email for {campaign_type} campaign. Product/Service: {product}. Target audience: {audience}. Goal: {goal}. Include subject line and email body.',
                'variables' => [
                    ['name' => 'campaign_type', 'type' => 'string', 'required' => true, 'description' => 'Type of email campaign'],
                    ['name' => 'product', 'type' => 'string', 'required' => true, 'description' => 'Product or service being promoted'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience'],
                    ['name' => 'goal', 'type' => 'string', 'required' => true, 'description' => 'Campaign goal'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.6,
                'tags' => ['email', 'marketing', 'conversion'],
                'is_custom' => false,
            ],

            'product_description' => [
                'id' => 'product_description',
                'name' => 'Product Description',
                'description' => 'Create compelling product descriptions',
                'category' => 'ecommerce',
                'system_prompt' => 'You are an e-commerce copywriter. Create compelling product descriptions that highlight benefits, address pain points, and drive sales.',
                'user_prompt_template' => 'Write a product description for {product_name}. Key features: {features}. Target audience: {audience}. Price point: {price_point}. Highlight benefits and include persuasive elements.',
                'variables' => [
                    ['name' => 'product_name', 'type' => 'string', 'required' => true, 'description' => 'Name of the product'],
                    ['name' => 'features', 'type' => 'string', 'required' => true, 'description' => 'Key product features'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target customer'],
                    ['name' => 'price_point', 'type' => 'string', 'required' => false, 'description' => 'Price range (budget, mid-range, premium)'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.7,
                'tags' => ['ecommerce', 'sales', 'product'],
                'is_custom' => false,
            ],

            'press_release' => [
                'id' => 'press_release',
                'name' => 'Press Release',
                'description' => 'Create professional press releases',
                'category' => 'pr',
                'system_prompt' => 'You are a PR professional. Write newsworthy press releases that follow industry standards and capture media attention.',
                'user_prompt_template' => 'Write a press release about {announcement}. Company: {company}. Key details: {details}. Target media: {media_type}. Include headline, dateline, and quotes.',
                'variables' => [
                    ['name' => 'announcement', 'type' => 'string', 'required' => true, 'description' => 'What is being announced'],
                    ['name' => 'company', 'type' => 'string', 'required' => true, 'description' => 'Company name'],
                    ['name' => 'details', 'type' => 'string', 'required' => true, 'description' => 'Key details and facts'],
                    ['name' => 'media_type', 'type' => 'string', 'required' => false, 'description' => 'Target media type'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.5,
                'tags' => ['pr', 'news', 'media'],
                'is_custom' => false,
            ],

            'ad_copy' => [
                'id' => 'ad_copy',
                'name' => 'Advertisement Copy',
                'description' => 'Create persuasive ad copy for various platforms',
                'category' => 'advertising',
                'system_prompt' => 'You are an advertising copywriter. Create persuasive, attention-grabbing ad copy that drives action. Focus on benefits, create urgency, and include strong CTAs.',
                'user_prompt_template' => 'Create ad copy for {platform} advertising {product}. Target audience: {audience}. Key benefit: {benefit}. Ad format: {format}. Include headline and description.',
                'variables' => [
                    ['name' => 'platform', 'type' => 'string', 'required' => true, 'description' => 'Advertising platform (Google, Facebook, etc.)'],
                    ['name' => 'product', 'type' => 'string', 'required' => true, 'description' => 'Product or service'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience'],
                    ['name' => 'benefit', 'type' => 'string', 'required' => true, 'description' => 'Main benefit or value proposition'],
                    ['name' => 'format', 'type' => 'string', 'required' => false, 'description' => 'Ad format or size'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.8,
                'tags' => ['advertising', 'ppc', 'conversion'],
                'is_custom' => false,
            ],

            'technical_documentation' => [
                'id' => 'technical_documentation',
                'name' => 'Technical Documentation',
                'description' => 'Create clear technical documentation',
                'category' => 'technical',
                'system_prompt' => 'You are a technical writer. Create clear, comprehensive documentation that helps users understand and implement technical concepts.',
                'user_prompt_template' => 'Create technical documentation for {topic}. Audience: {audience}. Include overview, step-by-step instructions, examples, and troubleshooting tips.',
                'variables' => [
                    ['name' => 'topic', 'type' => 'string', 'required' => true, 'description' => 'Technical topic or feature'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience (developers, end-users, etc.)'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.3,
                'tags' => ['technical', 'documentation', 'tutorial'],
                'is_custom' => false,
            ],

            'video_script' => [
                'id' => 'video_script',
                'name' => 'Video Script',
                'description' => 'Create engaging video scripts',
                'category' => 'video',
                'system_prompt' => 'You are a video script writer. Create engaging scripts with clear structure, compelling hooks, and strong calls-to-action.',
                'user_prompt_template' => 'Write a video script for {video_type} about {topic}. Duration: {duration} minutes. Target audience: {audience}. Include hook, main content, and CTA.',
                'variables' => [
                    ['name' => 'video_type', 'type' => 'string', 'required' => true, 'description' => 'Type of video (explainer, promotional, tutorial, etc.)'],
                    ['name' => 'topic', 'type' => 'string', 'required' => true, 'description' => 'Video topic'],
                    ['name' => 'duration', 'type' => 'number', 'required' => true, 'description' => 'Video duration in minutes'],
                    ['name' => 'audience', 'type' => 'string', 'required' => true, 'description' => 'Target audience'],
                ],
                'engine' => EngineEnum::OPENAI->value,
                'model' => EntityEnum::GPT_4O->value,
                'temperature' => 0.7,
                'tags' => ['video', 'script', 'content'],
                'is_custom' => false,
            ],
        ];
    }
}
