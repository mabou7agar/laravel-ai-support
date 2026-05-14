<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Services\Actions\ActionRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AgentManifestDoctor
{
    public function __construct(
        private readonly AgentSkillRegistry $skillRegistry,
        private readonly ActionRegistry $actionRegistry,
        private readonly ToolRegistry $toolRegistry
    ) {
    }

    /**
     * @return array{ok:bool,issues:array<int,array<string,mixed>>,summary:array<string,int>}
     */
    public function inspect(): array
    {
        $issues = [];
        $skills = $this->skillRegistry->skills(includeDisabled: true);
        $seen = [];

        foreach ($skills as $skill) {
            if (isset($seen[$skill->id])) {
                $issues[] = $this->issue('error', 'duplicate_skill_id', "Duplicate skill id [{$skill->id}].", ['skill_id' => $skill->id]);
            }
            $seen[$skill->id] = true;

            if (!$skill->enabled) {
                $issues[] = $this->issue('warning', 'disabled_skill', "Skill [{$skill->id}] is disabled.", ['skill_id' => $skill->id]);
            }

            if ($skill->actions === [] && $skill->tools === [] && empty($skill->metadata['collector'])) {
                $issues[] = $this->issue('warning', 'non_executable_skill', "Skill [{$skill->id}] has no executable action, tool, or collector.", ['skill_id' => $skill->id]);
            }

            foreach ($skill->actions as $actionId) {
                if (!$this->actionRegistry->has($actionId)) {
                    $issues[] = $this->issue('error', 'missing_action', "Skill [{$skill->id}] references missing action [{$actionId}].", [
                        'skill_id' => $skill->id,
                        'action_id' => $actionId,
                    ]);
                }
            }

            foreach ($skill->tools as $toolName) {
                if (!$this->toolRegistry->has($toolName)) {
                    $issues[] = $this->issue('error', 'missing_tool', "Skill [{$skill->id}] references missing tool [{$toolName}].", [
                        'skill_id' => $skill->id,
                        'tool' => $toolName,
                    ]);
                }
            }

        }

        $errors = count(array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? null) === 'error'));
        $warnings = count(array_filter($issues, static fn (array $issue): bool => ($issue['severity'] ?? null) === 'warning'));

        return [
            'ok' => $errors === 0,
            'issues' => $issues,
            'summary' => [
                'skills' => count($skills),
                'errors' => $errors,
                'warnings' => $warnings,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function issue(string $severity, string $code, string $message, array $context = []): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }
}
