<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

class MakeToolCommand extends ScaffoldAgentArtifactCommand
{
    protected $signature = 'ai:make-tool
                            {name? : Class name (e.g. LookupCustomer)}
                            {--model= : Optional model class used by generated examples}
                            {--kind= : Tool template kind: simple, lookup, upsert, action}
                            {--action= : Action id for action-backed tool templates}
                            {--description= : Description text used in generated class}
                            {--force : Overwrite file if it already exists}
                            {--no-register : Skip automatic manifest registration}';

    protected $description = 'Scaffold an AI agent tool and register it in the agent manifest';

    protected function resolveType(): ?string
    {
        return 'tool';
    }
}
