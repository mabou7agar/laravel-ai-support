<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;

abstract class AgentTool
{
    abstract public function getName(): string;
    
    abstract public function getDescription(): string;
    
    abstract public function getParameters(): array;
    
    abstract public function execute(array $parameters, UnifiedActionContext $context): ActionResult;

    public function previewConfirmation(array $parameters, UnifiedActionContext $context): ?ActionResult
    {
        return null;
    }

    /**
     * Describe fields returned in successful action payloads.
     *
     * @return array<int|string, mixed>
     */
    public function getResultSchema(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function getCapabilities(): array
    {
        return [];
    }

    public function getToolKind(): ?string
    {
        return null;
    }

    public function getEntityType(): ?string
    {
        return null;
    }

    /**
     * Describe application relations this tool can resolve or create.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRelations(): array
    {
        return [];
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function getConfirmationMessage(): ?string
    {
        return null;
    }

    public function validate(array $parameters): array
    {
        $errors = [];
        $requiredParams = $this->getRequiredParameters();
        
        foreach ($requiredParams as $param) {
            if (!array_key_exists($param, $parameters) || $parameters[$param] === null || $parameters[$param] === '') {
                $errors[] = "Missing required parameter: {$param}";
            }
        }
        
        return $errors;
    }

    protected function getRequiredParameters(): array
    {
        $params = $this->getParameters();
        return array_keys(array_filter($params, fn($p) => $p['required'] ?? false));
    }

    public function toArray(): array
    {
        return [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'parameters' => $this->getParameters(),
            'result_schema' => $this->getResultSchema(),
            'capabilities' => $this->getCapabilities(),
            'tool_kind' => $this->getToolKind(),
            'entity_type' => $this->getEntityType(),
            'relations' => $this->getRelations(),
            'requires_confirmation' => $this->requiresConfirmation(),
        ];
    }
}
