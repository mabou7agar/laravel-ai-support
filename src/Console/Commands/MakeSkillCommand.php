<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

class MakeSkillCommand extends ScaffoldAgentArtifactCommand
{
    protected $signature = 'ai:make-skill
                            {name? : Class name (e.g. CreateInvoice)}
                            {--description= : Description text used in generated class}
                            {--force : Overwrite file if it already exists}
                            {--no-register : Skip automatic manifest registration}';

    protected $description = 'Scaffold an AI agent skill and register it in the agent manifest';

    protected function resolveType(): ?string
    {
        return 'skill';
    }
}
