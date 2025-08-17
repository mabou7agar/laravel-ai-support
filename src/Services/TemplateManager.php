<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

use LaravelAIEngine\DTOs\AIResponse;

class TemplateManager
{
    private array $variables = [];
    private array $options = [];

    public function __construct(
        private string $templateName,
        private AIEngineManager $manager
    ) {}

    /**
     * Set template variables
     */
    public function with(array $variables): self
    {
        $this->variables = array_merge($this->variables, $variables);
        return $this;
    }

    /**
     * Set template options
     */
    public function options(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * Generate content using template
     */
    public function generate(): AIResponse
    {
        $template = $this->getTemplate($this->templateName);
        $prompt = $this->renderTemplate($template, $this->variables);

        $engine = $this->options['engine'] ?? config('ai-engine.default');
        $model = $this->options['model'] ?? null;

        $builder = $this->manager->engine($engine);
        
        if ($model) {
            $builder = $builder->model($model);
        }

        return $builder->generate($prompt);
    }

    /**
     * Get template content
     */
    private function getTemplate(string $name): string
    {
        // This would load templates from config or database
        $templates = config('ai-engine.templates', []);
        
        return $templates[$name] ?? throw new \InvalidArgumentException("Template '{$name}' not found");
    }

    /**
     * Render template with variables
     */
    private function renderTemplate(string $template, array $variables): string
    {
        $rendered = $template;

        foreach ($variables as $key => $value) {
            $rendered = str_replace("{{$key}}", $value, $rendered);
        }

        return $rendered;
    }
}
