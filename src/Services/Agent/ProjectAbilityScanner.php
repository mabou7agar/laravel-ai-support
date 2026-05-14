<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Actions\ActionRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;

class ProjectAbilityScanner
{
    public function __construct(
        private readonly AgentCollectionAdapter $collectionAdapter,
        private readonly ActionRegistry $actionRegistry,
        private readonly ToolRegistry $toolRegistry
    ) {
    }

    /**
     * @return array<int, AgentSkillDefinition>
     */
    public function discover(bool $useCache = true): array
    {
        $skills = [];

        foreach ($this->discoverActionSkills() as $skill) {
            $skills[$skill->id] = $skill;
        }

        foreach ($this->discoverCollectorSkills() as $skill) {
            $skills[$skill->id] = $skill;
        }

        foreach ($this->discoverModelSkills($useCache) as $skill) {
            $skills[$skill->id] ??= $skill;
        }

        ksort($skills);

        return array_values($skills);
    }

    /**
     * @return array<int, AgentSkillDefinition>
     */
    protected function discoverActionSkills(): array
    {
        $skills = [];

        try {
            $actions = $this->actionRegistry->all(enabledOnly: true);
        } catch (\Throwable) {
            return [];
        }

        foreach ($actions as $id => $action) {
            if (!is_array($action)) {
                continue;
            }

            $actionId = trim((string) ($action['id'] ?? $id));
            if ($actionId === '') {
                continue;
            }

            $operation = (string) ($action['operation'] ?? $action['type'] ?? 'action');
            $module = (string) ($action['module'] ?? $action['model'] ?? class_basename((string) ($action['model_class'] ?? '')));
            $name = (string) ($action['label'] ?? $this->headline($operation . ' ' . ($module !== '' ? $module : $actionId)));
            $description = (string) ($action['description'] ?? "Execute {$name}.");
            $triggers = $this->stringList($action['triggers'] ?? []);

            if ($triggers === []) {
                $triggers = [Str::lower($name)];
            }

            $skills[] = new AgentSkillDefinition(
                id: $this->skillId($actionId),
                name: $name,
                description: $description,
                triggers: $triggers,
                requiredData: $this->requiredData($action),
                actions: [$actionId],
                capabilities: ['action', $operation],
                requiresConfirmation: (bool) ($action['confirmation_required'] ?? true),
                enabled: false,
                metadata: [
                    'source' => 'action_registry',
                    'review_required' => true,
                    'action_id' => $actionId,
                    'module' => $module,
                ]
            );
        }

        return $skills;
    }

    /**
     * @return array<int, AgentSkillDefinition>
     */
    protected function discoverCollectorSkills(): array
    {
        $skills = [];

        foreach (AutonomousCollectorRegistry::getConfigs() as $name => $collector) {
            $id = $this->skillId((string) $name);
            $goal = (string) ($collector['goal'] ?? '');
            $description = (string) ($collector['description'] ?? $goal);

            if ($description === '') {
                $description = "Collect data for {$name}.";
            }

            $skills[] = new AgentSkillDefinition(
                id: $id,
                name: $this->headline((string) $name),
                description: $description,
                triggers: [$this->headline((string) $name), (string) $name],
                capabilities: ['collector'],
                requiresConfirmation: true,
                enabled: false,
                metadata: [
                    'source' => 'autonomous_collector_registry',
                    'review_required' => true,
                    'collector' => (string) $name,
                ]
            );
        }

        return $skills;
    }

    /**
     * @return array<int, AgentSkillDefinition>
     */
    protected function discoverModelSkills(bool $useCache): array
    {
        try {
            $models = $this->collectionAdapter->discoverForAgent($useCache);
        } catch (\Throwable) {
            return [];
        }

        $toolNames = array_keys($this->toolRegistry->all());
        $skills = [];

        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }

            $name = (string) ($model['display_name'] ?? $model['name'] ?? '');
            $class = (string) ($model['class'] ?? '');

            if ($name === '' || $class === '') {
                continue;
            }

            $id = $this->skillId($name);
            $operation = ((string) ($model['strategy'] ?? 'quick_action')) === 'guided_flow'
                ? 'collect_model_data'
                : 'manage_model';

            $skills[] = new AgentSkillDefinition(
                id: $id,
                name: "Manage {$name}",
                description: (string) ($model['description'] ?? "Manage {$name} records."),
                triggers: [
                    Str::lower("manage {$name}"),
                    Str::lower("list {$name}"),
                    Str::lower("create {$name}"),
                ],
                tools: $toolNames,
                capabilities: ['model', $operation],
                requiresConfirmation: true,
                enabled: false,
                metadata: [
                    'source' => 'model_discovery',
                    'review_required' => true,
                    'model_class' => $class,
                    'strategy' => (string) ($model['strategy'] ?? ''),
                    'complexity' => (string) ($model['complexity'] ?? ''),
                    'relationship_count' => (int) ($model['relationship_count'] ?? 0),
                ]
            );
        }

        return $skills;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<int, string>
     */
    protected function requiredData(array $action): array
    {
        $required = $this->stringList($action['required'] ?? $action['required_params'] ?? []);

        foreach ((array) ($action['parameters'] ?? []) as $key => $parameter) {
            if (!is_array($parameter) || !($parameter['required'] ?? false)) {
                continue;
            }

            $required[] = (string) $key;
        }

        return array_values(array_unique(array_filter($required)));
    }

    protected function skillId(string $value): string
    {
        $id = Str::snake(preg_replace('/Skill$/', '', $value) ?: $value);
        $id = preg_replace('/[^a-z0-9_]+/', '_', Str::lower($id)) ?? $id;
        $id = trim($id, '_');

        return $id !== '' ? $id : 'skill';
    }

    protected function headline(string $value): string
    {
        return ucwords(str_replace('_', ' ', Str::snake($value)));
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $value
        )));
    }
}
