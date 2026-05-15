<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

class MakeAgentCommand extends ScaffoldAgentArtifactCommand
{
    protected $signature = 'ai:make-agent
                            {name? : Class name (e.g. Invoice)}
                            {--model= : Model class for agent (e.g. App\\Models\\Invoice)}
                            {--description= : Description text used in generated class}
                            {--force : Overwrite file if it already exists}
                            {--no-register : Skip automatic manifest registration}';

    protected $description = 'Scaffold an AI agent config and register it in the agent manifest';

    protected function resolveType(): ?string
    {
        return 'agent';
    }
}
