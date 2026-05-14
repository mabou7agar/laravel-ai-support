<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Skills;

use Illuminate\Support\Str;
use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;

abstract class AgentSkill implements AgentSkillProvider
{
    public string $id = '';

    public string $name = '';

    public string $description = '';

    /**
     * @var array<int, string>
     */
    public array $triggers = [];

    /**
     * @var array<int, string>
     */
    public array $requiredData = [];

    /**
     * @var array<int, class-string<AgentTool>|string>
     */
    public array $tools = [];

    /**
     * @var array<int, string>
     */
    public array $actions = [];

    /**
     * @var array<int, string>
     */
    public array $capabilities = [];

    public bool $requiresConfirmation = true;

    public bool $enabled = true;

    /**
     * @var array<string, mixed>
     */
    public array $metadata = [];

    public function skills(): iterable
    {
        yield $this->definition();
    }

    public function definition(): AgentSkillDefinition
    {
        $metadata = array_merge([
            'planner' => 'skill_tool_auto',
            'target_json' => $this->targetJson(),
        ], $this->metadata());

        $finalTool = $this->normalizeToolName($this->propertyValue('finalTool', ''));
        if ($finalTool !== null) {
            $metadata['final_tool'] = $finalTool;
        }

        $examples = $this->examples();
        if ($examples !== []) {
            $metadata['examples'] = $examples;
        }

        return new AgentSkillDefinition(
            id: $this->skillId(),
            name: $this->skillName(),
            description: $this->skillDescription(),
            triggers: $this->stringList($this->triggers),
            requiredData: $this->stringList($this->requiredData),
            tools: $this->toolNames($this->tools),
            actions: $this->stringList($this->actions),
            capabilities: $this->stringList($this->capabilities ?: [$this->skillId()]),
            requiresConfirmation: $this->requiresConfirmation,
            enabled: $this->enabled,
            metadata: $metadata
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function targetJson(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function examples(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    protected function skillId(): string
    {
        $id = trim($this->id);
        if ($id !== '') {
            return $id;
        }

        return Str::snake(Str::beforeLast(class_basename(static::class), 'Skill') ?: class_basename(static::class));
    }

    protected function skillName(): string
    {
        $name = trim($this->name);
        if ($name !== '') {
            return $name;
        }

        return Str::headline($this->skillId());
    }

    protected function skillDescription(): string
    {
        $description = trim($this->description);
        if ($description !== '') {
            return $description;
        }

        return "Handle {$this->skillName()} requests using declared tools.";
    }

    /**
     * @param array<int, class-string<AgentTool>|string> $tools
     * @return array<int, string>
     */
    protected function toolNames(array $tools): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $tool): ?string => $this->normalizeToolName($tool),
            $tools
        ))));
    }

    protected function normalizeToolName(?string $tool): ?string
    {
        $tool = is_string($tool) ? trim($tool) : '';
        if ($tool === '') {
            return null;
        }

        if (class_exists($tool) && is_subclass_of($tool, AgentTool::class)) {
            try {
                $instance = app($tool);
                if ($instance instanceof AgentTool) {
                    $name = trim($instance->getName());

                    return $name !== '' ? $name : null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return $tool;
    }

    protected function propertyValue(string $property, mixed $default): mixed
    {
        return property_exists($this, $property) && isset($this->{$property})
            ? $this->{$property}
            : $default;
    }

    /**
     * @param array<int, mixed> $values
     * @return array<int, string>
     */
    protected function stringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $values
        ))));
    }
}
