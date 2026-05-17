<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\SDK;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class McpAppToolAdapter
{
    public function __construct(
        protected ToolRegistry $tools,
        protected ?AgentSkillRegistry $skills = null
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTools(bool $includeSkills = true): array
    {
        $tools = [];

        foreach ($this->tools->all() as $name => $tool) {
            $parameters = $tool->getParameters();
            $tools[] = [
                'name' => (string) $name,
                'description' => $tool->getDescription(),
                'inputSchema' => $this->inputSchema($parameters),
                'metadata' => [
                    'source' => 'tool',
                    'requires_confirmation' => $tool->requiresConfirmation(),
                ],
            ];
        }

        if ($includeSkills && $this->skills !== null) {
            foreach ($this->skills->skills(includeDisabled: false) as $skill) {
                $tools[] = [
                    'name' => 'skill.' . $skill->id,
                    'description' => trim($skill->name . '. ' . $skill->description),
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string', 'description' => 'User request to handle with this skill.'],
                        ],
                        'required' => ['message'],
                    ],
                    'metadata' => [
                        'source' => 'skill',
                        'skill_id' => $skill->id,
                        'requires_confirmation' => $skill->requiresConfirmation,
                    ],
                ];
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments, UnifiedActionContext $context): array
    {
        $tool = $this->tools->get($name);
        if ($tool === null) {
            return [
                'success' => false,
                'error' => "Tool [{$name}] is not registered.",
            ];
        }

        return $tool->execute($arguments, $context)->toArray();
    }

    /**
     * @param array<string, array<string, mixed>> $parameters
     * @return array<string, mixed>
     */
    protected function inputSchema(array $parameters): array
    {
        $properties = [];
        $required = [];

        foreach ($parameters as $name => $definition) {
            $properties[$name] = array_filter([
                'type' => $definition['type'] ?? 'string',
                'description' => $definition['description'] ?? null,
                'enum' => $definition['enum'] ?? null,
            ], static fn ($value): bool => $value !== null);

            if (($definition['required'] ?? false) === true) {
                $required[] = (string) $name;
            }
        }

        return array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], static fn ($value): bool => $value !== []);
    }
}
