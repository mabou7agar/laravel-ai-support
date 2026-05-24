<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class ConversationMemoryScopeResolver
{
    /**
     * @param array<string, mixed> $data
     * @return array{scope_type: string, scope_id: string|null, session_id: string|null}
     */
    public function fromArray(array $data): array
    {
        $scopeType = $this->nullableString($data['scope_type'] ?? $data['scopeType'] ?? null);
        $scopeId = $this->nullableString($data['scope_id'] ?? $data['scopeId'] ?? null);

        if ($scopeType !== null) {
            return [
                'scope_type' => $this->normalizeType($scopeType),
                'scope_id' => $scopeId,
                'session_id' => $this->nullableString($data['session_id'] ?? $data['sessionId'] ?? null),
            ];
        }

        foreach ($this->fallbackFields() as $type => $field) {
            $value = $this->nullableString($data[$field] ?? $data[$this->camel($field)] ?? null);
            if ($value !== null) {
                return [
                    'scope_type' => $type,
                    'scope_id' => $value,
                    'session_id' => $this->nullableString($data['session_id'] ?? $data['sessionId'] ?? null),
                ];
            }
        }

        return [
            'scope_type' => 'global',
            'scope_id' => null,
            'session_id' => $this->nullableString($data['session_id'] ?? $data['sessionId'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array{scope_type: string, scope_id: string|null, session_id: string|null}
     */
    public function fromContext(UnifiedActionContext $context, array $options = []): array
    {
        return $this->fromArray(array_merge($context->metadata, $options, [
            'user_id' => $context->userId,
            'session_id' => $context->sessionId,
        ]));
    }

    public function hash(string $scopeType, ?string $scopeId, ?string $sessionId): string
    {
        return hash('sha256', json_encode([
            'scope_type' => $this->normalizeType($scopeType),
            'scope_id' => $scopeId,
            'session_id' => $sessionId,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    protected function fallbackFields(): array
    {
        $configured = (array) config('ai-agent.conversation_memory.scope.fallback_fields', []);

        return $configured !== [] ? $configured : [
            'workspace' => 'workspace_id',
            'tenant' => 'tenant_id',
            'user' => 'user_id',
        ];
    }

    protected function normalizeType(string $scopeType): string
    {
        $scopeType = mb_strtolower(trim($scopeType));
        $scopeType = preg_replace('/[^\pL\pN_-]+/u', '_', $scopeType) ?? $scopeType;
        $scopeType = trim($scopeType, '_-');

        return $scopeType !== '' ? mb_substr($scopeType, 0, 80) : 'global';
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    protected function camel(string $snake): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $snake))));
    }
}
