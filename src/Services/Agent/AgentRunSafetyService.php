<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Models\AIAgentRun;

class AgentRunSafetyService
{
    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     *
     * @throws LockTimeoutException
     */
    public function withSessionLock(
        string $sessionId,
        callable $callback,
        ?string $tenantId = null,
        ?string $workspaceId = null,
        ?int $ttlSeconds = null,
        ?int $waitSeconds = null
    ): mixed {
        return $this->lock($this->sessionLockKey($sessionId, $tenantId, $workspaceId), $callback, $ttlSeconds, $waitSeconds);
    }

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     *
     * @throws LockTimeoutException
     */
    public function withRunLock(
        AIAgentRun|int|string $run,
        callable $callback,
        ?int $ttlSeconds = null,
        ?int $waitSeconds = null
    ): mixed {
        $runId = $run instanceof AIAgentRun ? (string) $run->id : (string) $run;

        return $this->lock("ai-agent:run-lock:{$runId}", $callback, $ttlSeconds, $waitSeconds);
    }

    public function rememberMessage(
        string $sessionId,
        string $message,
        int|string|null $userId = null,
        ?string $tenantId = null,
        ?string $workspaceId = null,
        ?int $ttlSeconds = null
    ): bool {
        return Cache::add(
            $this->duplicateMessageKey($sessionId, $message, $userId, $tenantId, $workspaceId),
            true,
            max(1, $ttlSeconds ?? (int) config('ai-agent.run_safety.duplicate_message_ttl_seconds', 120))
        );
    }

    public function rememberIdempotencyKey(string $key, array $scope = [], ?int $ttlSeconds = null): bool
    {
        return Cache::add(
            $this->idempotencyCacheKey($key, $scope),
            true,
            max(1, $ttlSeconds ?? (int) config('ai-agent.run_safety.duplicate_message_ttl_seconds', 120))
        );
    }

    public function sessionLockKey(string $sessionId, ?string $tenantId = null, ?string $workspaceId = null): string
    {
        $scope = $this->scopeKey($tenantId, $workspaceId);

        return "ai-agent:session-lock:{$scope}:" . sha1($sessionId);
    }

    public function duplicateMessageKey(
        string $sessionId,
        string $message,
        int|string|null $userId = null,
        ?string $tenantId = null,
        ?string $workspaceId = null
    ): string {
        $scope = $this->scopeKey($tenantId, $workspaceId);
        $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $message) ?? $message));

        return 'ai-agent:duplicate-message:' . sha1(implode('|', [
            $scope,
            $sessionId,
            (string) $userId,
            $normalized,
        ]));
    }

    public function idempotencyCacheKey(string $key, array $scope = []): string
    {
        return 'ai-agent:idempotency:' . sha1(json_encode([
            'key' => $key,
            'scope' => $scope,
        ], JSON_THROW_ON_ERROR));
    }

    public function scopeFromOptions(array $options): array
    {
        return $this->currentScope($options);
    }

    public function currentScope(array $options = []): array
    {
        $tenantId = $this->nullableString($options['tenant_id'] ?? $options['tenant'] ?? null);
        $workspaceId = $this->nullableString($options['workspace_id'] ?? $options['workspace'] ?? null);

        if ($tenantId === null && app()->bound(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class)) {
            try {
                $tenantId = $this->nullableString(app(\LaravelAIEngine\Services\Tenant\MultiTenantVectorService::class)->getCurrentTenantId());
            } catch (\Throwable) {
                $tenantId = null;
            }
        }

        if ($workspaceId === null && function_exists('session')) {
            $workspaceId = $this->nullableString(session('workspace_id') ?? session('current_workspace_id'));
        }

        return [
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceId,
        ];
    }

    public function scopeKey(array|string|null $scopeOrTenant = [], ?string $workspaceId = null): string
    {
        $scope = is_array($scopeOrTenant)
            ? $this->currentScope($scopeOrTenant)
            : ['tenant_id' => $this->nullableString($scopeOrTenant), 'workspace_id' => $this->nullableString($workspaceId)];

        return sha1(json_encode([
            'tenant_id' => $scope['tenant_id'] ?? null,
            'workspace_id' => $scope['workspace_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    public function applyScopeToMetadata(array $metadata): array
    {
        $scope = $this->currentScope($metadata);

        foreach ($scope as $key => $value) {
            if ($value !== null && !array_key_exists($key, $metadata)) {
                $metadata[$key] = $value;
            }
        }

        $metadata['scope_key'] ??= $this->scopeKey($scope);

        return $metadata;
    }

    public function assertRunScope(AIAgentRun $run, array $scope): void
    {
        $tenantId = $this->nullableString($scope['tenant_id'] ?? null);
        $workspaceId = $this->nullableString($scope['workspace_id'] ?? null);

        if ((bool) config('vector-access-control.enable_tenant_scope', true)
            && $run->tenant_id !== null
            && $tenantId !== null
            && (string) $run->tenant_id !== $tenantId
        ) {
            throw new \RuntimeException('Agent run tenant scope does not match the current execution scope.');
        }

        if ((bool) config('vector-access-control.enable_workspace_scope', true)
            && $run->workspace_id !== null
            && $workspaceId !== null
            && (string) $run->workspace_id !== $workspaceId
        ) {
            throw new \RuntimeException('Agent run workspace scope does not match the current execution scope.');
        }
    }

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    protected function lock(string $key, callable $callback, ?int $ttlSeconds = null, ?int $waitSeconds = null): mixed
    {
        $ttl = max(1, $ttlSeconds ?? (int) config('ai-agent.run_safety.lock_ttl_seconds', 60));
        $wait = max(0, $waitSeconds ?? (int) config('ai-agent.run_safety.lock_wait_seconds', 5));

        return Cache::lock($key, $ttl)->block($wait, $callback);
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
