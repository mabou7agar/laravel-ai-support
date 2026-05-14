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

        if ((bool) config('ai-engine.provider_tools.approvals.require_for_sensitive_payloads', true)
            && $this->payloadSensitivity($tool)['sensitive'] === true
        ) {
            return true;
        }

        $requiredTools = config('ai-engine.provider_tools.approvals.require_for', [
            'computer_use',
            'mcp_server',
            'code_interpreter',
        ]);

        if (in_array($this->toolName($tool), array_map('strval', (array) $requiredTools), true)) {
            return true;
        }

        $threshold = config('ai-engine.provider_tools.approvals.require_risk_level_at_or_above');
        if (is_string($threshold) && $threshold !== '') {
            return $this->riskScore($this->riskLevel($tool)) >= $this->riskScore($threshold);
        }

        return false;
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

    public function payloadSensitivity(array $payload): array
    {
        $keys = array_map('strtolower', (array) config('ai-engine.provider_tools.approvals.sensitive_keys', [
            'password',
            'secret',
            'token',
            'api_key',
            'authorization',
            'cookie',
            'private_key',
            'card_number',
            'ssn',
        ]));
        $patterns = (array) config('ai-engine.provider_tools.approvals.sensitive_patterns', [
            '/sk-[A-Za-z0-9_\-]{16,}/',
            '/-----BEGIN (?:RSA |EC |OPENSSH |)PRIVATE KEY-----/',
            '/\b\d{13,19}\b/',
        ]);

        $matches = [];
        $this->walkPayload($payload, '', function (string $path, mixed $value) use (&$matches, $keys, $patterns): void {
            $leaf = strtolower((string) str($path)->afterLast('.'));
            foreach ($keys as $key) {
                if ($key !== '' && str_contains($leaf, $key)) {
                    $matches[] = ['path' => $path, 'reason' => 'sensitive_key'];
                    return;
                }
            }

            if (!is_scalar($value)) {
                return;
            }

            $stringValue = (string) $value;
            foreach ($patterns as $pattern) {
                if (@preg_match((string) $pattern, $stringValue) === 1) {
                    $matches[] = ['path' => $path, 'reason' => 'sensitive_pattern'];
                    return;
                }
            }
        });

        return [
            'sensitive' => $matches !== [],
            'matches' => $matches,
        ];
    }

    protected function riskScore(string $risk): int
    {
        return match (strtolower($risk)) {
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }

    protected function walkPayload(array $payload, string $prefix, callable $callback): void
    {
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $callback($path, $value);

            if (is_array($value)) {
                $this->walkPayload($value, $path, $callback);
            }
        }
    }
}
