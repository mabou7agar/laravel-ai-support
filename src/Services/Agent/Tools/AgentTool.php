<?php

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\DTOs\ActionResult;

abstract class AgentTool
{
    abstract public function getName(): string;
    
    abstract public function getDescription(): string;
    
    abstract public function getParameters(): array;
    
    abstract public function execute(array $parameters, UnifiedActionContext $context): ActionResult;

    public function validate(array $parameters): array
    {
        $errors = [];
        $requiredParams = $this->getRequiredParameters();
        
        foreach ($requiredParams as $param) {
            if (!isset($parameters[$param]) || empty($parameters[$param])) {
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
        ];
    }
}
