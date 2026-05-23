<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AiNativeToolClassifier
{
    public function __construct(private readonly ToolRegistry $tools) {}

    public function isLookupTool(string $toolName): bool
    {
        $toolName = strtolower(trim($toolName));
        $tool = $this->tools->get($toolName);
        if ($tool instanceof AgentTool) {
            $kind = strtolower((string) $tool->getToolKind());
            $capabilities = array_map('strtolower', $tool->getCapabilities());
            if (in_array($kind, ['lookup', 'search', 'find', 'read'], true)
                || array_intersect($capabilities, ['lookup', 'search', 'find', 'read']) !== []) {
                return true;
            }
        }

        return str_contains($toolName, 'find') || str_contains($toolName, 'search') || str_contains($toolName, 'lookup');
    }

    public function isWriteTool(string $toolName): bool
    {
        $toolName = strtolower(trim($toolName));
        $tool = $this->tools->get($toolName);
        if ($tool instanceof AgentTool) {
            $kind = strtolower((string) $tool->getToolKind());
            $capabilities = array_map('strtolower', $tool->getCapabilities());
            if (in_array($kind, ['write', 'create', 'update', 'delete'], true)
                || array_intersect($capabilities, ['write', 'create', 'update', 'delete']) !== []) {
                return true;
            }
        }

        return preg_match('/^(create|update|delete|remove)_/i', $toolName) === 1;
    }

    public function entityFor(string $toolName): string
    {
        $toolName = strtolower(trim($toolName));
        $tool = $this->tools->get($toolName);
        if ($tool instanceof AgentTool) {
            $entity = strtolower(trim((string) $tool->getEntityType()));
            if ($entity !== '') {
                return $entity;
            }
        }

        return preg_replace('/^(find|lookup|search|create|update|delete|remove)_/i', '', $toolName) ?: '';
    }
}
