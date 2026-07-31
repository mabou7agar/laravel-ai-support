<?php

declare(strict_types=1);

namespace LaravelAIEngine\Repositories;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class AgentContextRepository
{
    public function find(string $sessionId, mixed $userId = null, ?string $scope = null): ?UnifiedActionContext
    {
        $data = Cache::get($this->cacheKey($sessionId, $userId, $scope));

        if (!is_array($data)) {
            return null;
        }

        $context = UnifiedActionContext::fromArray($data);
        $context->contextScope = $this->normalizeScope($scope);

        return $context;
    }

    public function save(UnifiedActionContext $context): void
    {
        Cache::put(
            $this->cacheKey($context->sessionId, $context->userId, $context->contextScope),
            $context->toArray(),
            now()->addHours(24)
        );
    }

    public function forget(string $sessionId, mixed $userId = null, ?string $scope = null): void
    {
        Cache::forget($this->cacheKey($sessionId, $userId, $scope));
    }

    public function exists(string $sessionId, mixed $userId = null, ?string $scope = null): bool
    {
        return $this->find($sessionId, $userId, $scope) instanceof UnifiedActionContext;
    }

    public function cacheKey(string $sessionId, mixed $userId = null, ?string $scope = null): string
    {
        $identity = [
            'session_id' => $sessionId,
            'user_id' => $this->userKey($userId),
        ];

        $scope = $this->normalizeScope($scope);
        if ($scope !== null) {
            // The raw tenant/workspace value never appears in cache keys or logs.
            $identity['scope_hash'] = hash('sha256', $scope);
        }

        return 'agent_context:' . sha1(json_encode($identity, JSON_THROW_ON_ERROR));
    }

    private function normalizeScope(?string $scope): ?string
    {
        $scope = trim((string) $scope);

        return $scope === '' ? null : $scope;
    }

    private function userKey(mixed $userId): string
    {
        return $userId === null || $userId === '' ? 'guest' : (string) $userId;
    }

}
