<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

class ProviderToolPolicyService
{
    public function approvalsEnabled(): bool
    {
        return (bool) config('ai-engine.provider_tools.approvals.enabled', true);
    }

    public function auditAll(): bool
    {
        return (bool) config('ai-engine.provider_tools.audit.enabled', true);
    }

    public function normalizeTools(array $tools): array
    {
        $normalized = [];

        foreach ($tools as $tool) {
            if (!is_array($tool)) {
                continue;
            }

            $name = $this->toolName($tool);
            if ($name === '') {
                continue;
            }

            $normalized[] = array_merge($tool, ['type' => $name]);
        }

        return $normalized;
    }

    public function requiresApproval(array $tool): bool
    {
        if (!$this->approvalsEnabled()) {
            return false;
        }

        $policy = $tool['approval_policy'] ?? null;
        if ($policy === 'never' || $policy === false) {
            return false;
        }

        if (($tool['requires_approval'] ?? false) === true || $policy === 'always') {
            return true;
        }

        $requiredTools = config('ai-engine.provider_tools.approvals.require_for', [
            'computer_use',
            'mcp_server',
            'code_interpreter',
        ]);

        return in_array($this->toolName($tool), array_map('strval', (array) $requiredTools), true);
    }

    public function riskLevel(array $tool): string
    {
        $name = $this->toolName($tool);
        $riskLevels = config('ai-engine.provider_tools.approvals.risk_levels', []);

        return (string) ($riskLevels[$name] ?? match ($name) {
            'computer_use' => 'high',
            'mcp_server', 'code_interpreter' => 'medium',
            default => 'low',
        });
    }

    public function toolName(array $tool): string
    {
        return trim((string) ($tool['type'] ?? $tool['name'] ?? $tool['tool'] ?? ''));
    }
}
