<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

use JsonSerializable;

class AgentSkillDefinition implements JsonSerializable
{
    /**
     * @param array<int, string> $triggers
     * @param array<int, string> $requiredData
     * @param array<int, string> $tools
     * @param array<int, string> $actions
     * @param array<int, string> $workflows
     * @param array<int, string> $capabilities
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly array $triggers = [],
        public readonly array $requiredData = [],
        public readonly array $tools = [],
        public readonly array $actions = [],
        public readonly array $workflows = [],
        public readonly array $capabilities = [],
        public readonly bool $requiresConfirmation = true,
        public readonly bool $enabled = true,
        public readonly array $metadata = []
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            name: (string) ($data['name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            triggers: self::stringList($data['triggers'] ?? []),
            requiredData: self::stringList($data['required_data'] ?? $data['requiredData'] ?? []),
            tools: self::stringList($data['tools'] ?? []),
            actions: self::stringList($data['actions'] ?? []),
            workflows: self::stringList($data['workflows'] ?? []),
            capabilities: self::stringList($data['capabilities'] ?? []),
            requiresConfirmation: (bool) ($data['requires_confirmation'] ?? $data['requiresConfirmation'] ?? true),
            enabled: (bool) ($data['enabled'] ?? true),
            metadata: (array) ($data['metadata'] ?? [])
        );
    }

    public function capabilityDocument(): AgentCapabilityDocument
    {
        return new AgentCapabilityDocument(
            id: 'skill:' . $this->id,
            text: trim($this->name . '. ' . $this->description),
            payload: [
                'type' => 'skill',
                'skill_id' => $this->id,
                'triggers' => $this->triggers,
                'required_data' => $this->requiredData,
                'tools' => $this->tools,
                'actions' => $this->actions,
                'workflows' => $this->workflows,
                'requires_confirmation' => $this->requiresConfirmation,
            ],
            metadata: array_merge($this->metadata, [
                'enabled' => $this->enabled,
                'capabilities' => $this->capabilities,
            ])
        );
    }

    /**
     * @return array{
     *     id:string,
     *     name:string,
     *     description:string,
     *     triggers:array<int,string>,
     *     required_data:array<int,string>,
     *     tools:array<int,string>,
     *     actions:array<int,string>,
     *     workflows:array<int,string>,
     *     capabilities:array<int,string>,
     *     requires_confirmation:bool,
     *     enabled:bool,
     *     metadata:array<string,mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'triggers' => $this->triggers,
            'required_data' => $this->requiredData,
            'tools' => $this->tools,
            'actions' => $this->actions,
            'workflows' => $this->workflows,
            'capabilities' => $this->capabilities,
            'requires_confirmation' => $this->requiresConfirmation,
            'enabled' => $this->enabled,
            'metadata' => $this->metadata,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    protected static function stringList(mixed $value): array
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
