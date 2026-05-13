<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\AgentManifestEditorService;
use LaravelAIEngine\Services\Agent\ProjectAbilityScanner;

class DiscoverAgentSkillsCommand extends Command
{
    protected $signature = 'ai-engine:skills:discover
                            {--write : Write discovered skills to the agent manifest as drafts}
                            {--enable : Enable written skills instead of creating disabled drafts}
                            {--refresh : Refresh model discovery cache}
                            {--json : Output discovered skills as JSON}';

    protected $description = 'Scan project abilities and generate draft AI skill definitions';

    public function handle(ProjectAbilityScanner $scanner, AgentManifestEditorService $manifestEditor): int
    {
        $skills = $scanner->discover(useCache: !$this->option('refresh'));

        if ($this->option('json')) {
            $this->line(json_encode(array_map(
                static fn (AgentSkillDefinition $skill): array => $skill->toArray(),
                $skills
            ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->displaySkills($skills);
        }

        if ($this->option('write')) {
            $definitions = [];
            foreach ($skills as $skill) {
                $definition = $skill->toArray();
                $definition['enabled'] = (bool) $this->option('enable');
                $definition['metadata']['review_required'] = !$this->option('enable');
                $definitions[$skill->id] = $definition;
            }

            $written = $manifestEditor->putSkillDefinitions($definitions);
            $this->newLine();
            $this->info("Wrote {$written} skill definition(s) to {$manifestEditor->manifestPath()}");

            if (!$this->option('enable')) {
                $this->line('Skills were written as disabled drafts. Review and enable them before production use.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<int, AgentSkillDefinition> $skills
     */
    protected function displaySkills(array $skills): void
    {
        if ($skills === []) {
            $this->warn('No skill candidates discovered.');
            return;
        }

        $this->table(
            ['ID', 'Name', 'Triggers', 'Actions', 'Tools', 'Source'],
            array_map(static function (AgentSkillDefinition $skill): array {
                return [
                    $skill->id,
                    $skill->name,
                    implode(', ', array_slice($skill->triggers, 0, 3)),
                    implode(', ', $skill->actions),
                    implode(', ', array_slice($skill->tools, 0, 3)),
                    (string) ($skill->metadata['source'] ?? ''),
                ];
            }, $skills)
        );
    }
}
