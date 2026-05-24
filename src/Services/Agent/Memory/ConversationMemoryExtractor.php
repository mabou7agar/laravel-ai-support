<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\ConversationMemoryItem;
use LaravelAIEngine\Services\AIEngineService;

class ConversationMemoryExtractor
{
    public function __construct(
        protected ConversationMemoryPolicy $policy,
        protected AIEngineService $ai
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $scope
     * @return array<int, ConversationMemoryItem>
     */
    public function extract(array $messages, array $scope = []): array
    {
        if (!$this->policy->enabled() || $messages === []) {
            return [];
        }

        $custom = $this->policy->customExtractorClass();
        if ($custom !== null && class_exists($custom)) {
            $service = app($custom);
            if (is_object($service) && method_exists($service, 'extract')) {
                return array_values(array_filter(
                    (array) $service->extract($messages, $scope),
                    static fn (mixed $item): bool => $item instanceof ConversationMemoryItem
                ));
            }
        }

        if ($this->policy->extractor() === 'none') {
            return [];
        }

        return $this->extractWithAi($messages, $scope);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $scope
     * @return array<int, ConversationMemoryItem>
     */
    protected function extractWithAi(array $messages, array $scope): array
    {
        $engine = $this->policy->engine();
        $model = $this->policy->model();
        if ($engine === null || $model === null) {
            return [];
        }

        $response = $this->ai->generate(new AIRequest(
            prompt: $this->prompt($messages),
            engine: $engine,
            model: $model,
            maxTokens: 700,
            temperature: 0.0,
            metadata: ['context' => 'conversation_memory_extraction']
        ));

        if (!$response->isSuccessful()) {
            return [];
        }

        $decoded = $this->decodeJsonArray($response->getContent());
        if ($decoded === []) {
            return [];
        }

        $items = [];
        foreach (array_slice($decoded, 0, $this->policy->maxMemoriesPerTurn()) as $payload) {
            if (!is_array($payload)) {
                continue;
            }

            if ($this->rejectsExtractedPayload($payload)) {
                continue;
            }

            $summary = $this->limit((string) ($payload['summary'] ?? ''), 220);
            if ($summary === '') {
                continue;
            }

            $namespace = $this->normalizeKey((string) ($payload['namespace'] ?? 'conversation'), 'conversation');
            $key = $this->normalizeKey((string) ($payload['key'] ?? ''), '');
            if ($key === '') {
                $key = $this->stableKey($namespace, $summary);
            }

            $ttlDays = $this->policy->ttlDays();
            $items[] = ConversationMemoryItem::fromArray([
                'namespace' => $namespace,
                'key' => $key,
                'value' => $this->limit((string) ($payload['value'] ?? ''), 500) ?: null,
                'summary' => $summary,
                'scope_type' => $scope['scope_type'] ?? $scope['scopeType'] ?? null,
                'scope_id' => $scope['scope_id'] ?? $scope['scopeId'] ?? null,
                'user_id' => $scope['user_id'] ?? $scope['userId'] ?? null,
                'tenant_id' => $scope['tenant_id'] ?? $scope['tenantId'] ?? null,
                'workspace_id' => $scope['workspace_id'] ?? $scope['workspaceId'] ?? null,
                'session_id' => $scope['session_id'] ?? $scope['sessionId'] ?? null,
                'confidence' => min(1.0, max(0.0, (float) ($payload['confidence'] ?? 0.7))),
                'metadata' => [
                    'source' => 'ai_extractor',
                ],
                'expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
            ]);
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    protected function prompt(array $messages): string
    {
        $content = json_encode(array_map(static fn (array $message): array => [
            'role' => (string) ($message['role'] ?? 'user'),
            'content' => (string) ($message['content'] ?? ''),
        ], $messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        $content = $this->limit((string) $content, $this->policy->maxExtractionInputChars());

        return implode("\n", [
            'You extract durable conversation memories for a Laravel package.',
            'Return JSON array only.',
            'Extract only stable user/session/workspace preferences, facts, constraints, unresolved goals, and decisions.',
            'Do not require English trigger phrases. Infer memory-worthiness from meaning in any language.',
            'Do not include application/database records, record identifiers, one-off customer/order/item details, product catalogs, private credentials, secrets, payment data, or transient chit-chat.',
            'Each item must contain: namespace, key, value, summary, confidence.',
            'Keep every summary under 220 characters.',
            'Return [] when there is nothing worth remembering.',
            '',
            'Compacted conversation JSON:',
            $content,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function decodeJsonArray(string $content): array
    {
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)) ?? $content);
        $decoded = json_decode($content, true);

        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        if (preg_match('/\[[\s\S]*\]/', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            return is_array($decoded) && array_is_list($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function rejectsExtractedPayload(array $payload): bool
    {
        $key = $this->normalizeKey((string) ($payload['key'] ?? ''), '');
        if ($key !== '' && $this->rejectsMemoryKey($key)) {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', array_filter(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            [
                $payload['namespace'] ?? '',
                $payload['key'] ?? '',
                $payload['value'] ?? '',
                $payload['summary'] ?? '',
            ]
        ))));

        if ($haystack === '') {
            return true;
        }

        foreach ((array) config('ai-agent.conversation_memory.rejected_terms', []) as $term) {
            $term = mb_strtolower(trim((string) $term));
            if ($term !== '' && str_contains($haystack, $term)) {
                return true;
            }
        }

        return false;
    }

    protected function rejectsMemoryKey(string $key): bool
    {
        foreach ((array) config('ai-agent.conversation_memory.rejected_key_suffixes', []) as $suffix) {
            $suffix = mb_strtolower(trim((string) $suffix));
            if ($suffix !== '' && str_ends_with($key, $suffix)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeKey(string $value, string $default): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[^\pL\pN_]+/u', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : $default;
    }

    protected function stableKey(string $namespace, string $summary): string
    {
        return $this->normalizeKey($namespace, 'memory') . '_' . substr(sha1($summary), 0, 16);
    }

    protected function limit(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > $limit ? mb_substr($value, 0, max(0, $limit - 3)) . '...' : $value;
    }
}
