<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

use LaravelAIEngine\Services\Agent\Tools\GenerateWebsiteTool;

/**
 * Conversational website-building skill. Collects the design brief, then builds
 * a complete site grounded in the design-intelligence knowledge base via the
 * {@see GenerateWebsiteTool}.
 */
class GenerateWebsiteSkill extends AgentSkill
{
    public string $id = 'generate_website';

    public string $name = 'Generate Website';

    public string $description = 'Generate a complete, accessible, on-brand website, landing page, or page '
        . 'from a description, grounded in a resolved design system.';

    /**
     * @var array<int, string>
     */
    public array $triggers = [
        'generate website',
        'build website',
        'create website',
        'design website',
        'build a landing page',
        'create a landing page',
        'design a landing page',
        'build me a site',
        'generate a web page',
    ];

    /**
     * @var array<int, string>
     */
    public array $capabilities = ['website.create', 'design.generate'];

    public bool $requiresConfirmation = false;

    public string $prompt = 'Help the user describe the website they want (purpose, audience, and any style preference), '
        . 'then build it with the generate_website tool. The design system (layout, style, color tokens, fonts) is '
        . 'resolved automatically — do not invent colors or fonts. Default the stack to html unless the user asks otherwise.';

    public function configure(SkillBuilder $skill): void
    {
        $skill->target()
            ->text('prompt')
            ->text('project_name')
            ->select('stack', ['html', 'react', 'next', 'vue', 'svelte'], 'html')
            ->text('page');

        $skill->final(GenerateWebsiteTool::class);
    }
}
