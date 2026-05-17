<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Repositories\ConversationMemoryRepository;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryExtractor;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemoryPolicy;
use LaravelAIEngine\Services\Agent\Memory\ConversationMemorySemanticIndex;

class ConversationContextCompactor
{
    public function __construct(
        protected ?ConversationMemoryPolicy $memoryPolicy = null,
        protected ?ConversationMemoryExtractor $memoryExtractor = null,
        protected ?ConversationMemoryRepository $memoryRepository = null,
        protected ?ConversationMemorySemanticIndex $memorySemanticIndex = null,
    ) {
    }

    public function compact(UnifiedActionContext $context): void
    {
        $beforeChars = $this->historyChars($context->conversationHistory);

        if (!$this->enabled()) {
            $context->conversationHistory = $this->sanitizeMessages(
                array_slice($context->conversationHistory, -$this->maxMessages())
            );
            $this->storeMetrics($context, $beforeChars);

            return;
        }

        $history = $this->sanitizeMessages($context->conversationHistory);
        $shouldCompact = count($history) > $this->maxMessages()
            || $this->historyChars($history) > $this->maxTotalChars();

        if (!$shouldCompact) {
            $context->conversationHistory = $history;
            $this->storeMetrics($context, $beforeChars);
            return;
        }

        $keep = max(1, $this->keepRecentMessages());
        $recent = array_slice($history, -$keep);
        $older = array_slice($history, 0, max(0, count($history) - $keep));

        $context->metadata['conversation_summary'] = $this->buildSummary(
            (string) ($context->metadata['conversation_summary'] ?? ''),
            $older
        );
        $context->metadata['conversation_compacted_messages'] = (int) ($context->metadata['conversation_compacted_messages'] ?? 0) + count($older);
        $context->metadata['conversation_last_compacted_at'] = now()->toIso8601String();
        $context->conversationHistory = $recent;
        $this->extractConversationMemories($context, $older);
        $this->storeMetrics($context, $beforeChars);
    }

    public function summaryForPrompt(UnifiedActionContext $context): string
    {
        return trim((string) ($context->metadata['conversation_summary'] ?? ''));
    }

    /**
     * @return array{prompt_size_chars:int,summary_size_chars:int,recent_memory_size_chars:int,retrieved_memory_size_chars:int,total_context_size_chars:int,compacted_messages:int}
     */
    public function metrics(UnifiedActionContext $context): array
    {
        $summarySize = strlen($this->summaryForPrompt($context));
        $recentSize = $this->historyChars($context->conversationHistory);
        $retrievedSize = strlen((string) ($context->metadata['retrieved_memory'] ?? ''));

        return [
            'prompt_size_chars' => $summarySize + $recentSize + $retrievedSize,
            'summary_size_chars' => $summarySize,
            'recent_memory_size_chars' => $recentSize,
            'retrieved_memory_size_chars' => $retrievedSize,
            'total_context_size_chars' => $summarySize + $recentSize + $retrievedSize,
            'compacted_messages' => (int) ($context->metadata['conversation_compacted_messages'] ?? 0),
        ];
    }

    private function enabled(): bool
    {
        return (bool) $this->config('ai-agent.context_compaction.enabled', true);
    }

    private function maxMessages(): int
    {
        return max(2, (int) $this->config('ai-agent.context_compaction.max_messages', 12));
    }

    private function keepRecentMessages(): int
    {
        return max(2, (int) $this->config('ai-agent.context_compaction.keep_recent_messages', 6));
    }

    private function maxMessageChars(): int
    {
        return max(200, (int) $this->config('ai-agent.context_compaction.max_message_chars', 2000));
    }

    private function maxTotalChars(): int
    {
        return max($this->maxMessageChars(), (int) $this->config('ai-agent.context_compaction.max_total_chars', 12000));
    }

    private function maxSummaryChars(): int
    {
        return max(500, (int) $this->config('ai-agent.context_compaction.max_summary_chars', 4000));
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeMessages(array $messages): array
    {
        $limit = $this->maxMessageChars();

        $sanitized = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));

            if (mb_strlen($content) > $limit) {
                $content = mb_substr($content, 0, $limit) . '...';
            }

            $message['role'] = in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)
                ? $message['role']
                : 'user';
            $message['content'] = $content;

            $sanitized[] = $message;
        }

        return array_values($sanitized);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     */
    private function historyChars(array $messages): int
    {
        return array_sum(array_map(
            static fn (array $message): int => strlen((string) ($message['content'] ?? '')),
            $messages
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $older
     */
    private function buildSummary(string $existingSummary, array $older): string
    {
        $lines = [];
        $existingSummary = trim($existingSummary);

        if ($existingSummary !== '') {
            $lines[] = $existingSummary;
        }

        foreach ($older as $message) {
            $content = trim(preg_replace('/\s+/', ' ', (string) ($message['content'] ?? '')) ?? '');
            if ($content === '') {
                continue;
            }

            $role = strtolower((string) ($message['role'] ?? 'user'));
            $excerpt = mb_substr($content, 0, (int) $this->config('ai-agent.context_compaction.summary_message_chars', 240));
            if (mb_strlen($content) > mb_strlen($excerpt)) {
                $excerpt .= '...';
            }

            $lines[] = '- ' . $role . ': ' . $excerpt;
        }

        return $this->trimSummary(implode("\n", array_filter($lines)));
    }

    private function trimSummary(string $summary): string
    {
        $summary = trim($summary);
        $limit = $this->maxSummaryChars();

        if (mb_strlen($summary) <= $limit) {
            return $summary;
        }

        return mb_substr($summary, -$limit);
    }

    private function storeMetrics(UnifiedActionContext $context, int $beforeChars): void
    {
        $metrics = $this->metrics($context);
        $metrics['pre_compaction_history_size_chars'] = $beforeChars;
        $context->metadata['conversation_context_metrics'] = $metrics;
    }

    /**
     * @param array<int, array<string, mixed>> $older
     */
    private function extractConversationMemories(UnifiedActionContext $context, array $older): void
    {
        if ($older === []) {
            return;
        }

        try {
            $policy = $this->memoryPolicy();
            if (!$policy->enabled() || !$policy->extractOnCompaction()) {
                return;
            }

            $items = $this->memoryExtractor()->extract($older, $this->memoryScope($context));
            foreach ($items as $item) {
                $stored = $this->memoryRepository()->upsert($item);
                if ($policy->semanticIndexOnWrite()) {
                    $this->memorySemanticIndex()->index($stored);
                }
            }

            $context->metadata['conversation_memory_extracted'] = (int) ($context->metadata['conversation_memory_extracted'] ?? 0) + count($items);
            unset($context->metadata['conversation_memory_extraction_error']);
        } catch (\Throwable $exception) {
            $context->metadata['conversation_memory_extraction_error'] = $exception->getMessage();
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function memoryScope(UnifiedActionContext $context): array
    {
        $tenantKey = (string) $this->config('ai-agent.conversation_memory.scopes.tenant_key', 'tenant_id');
        $workspaceKey = (string) $this->config('ai-agent.conversation_memory.scopes.workspace_key', 'workspace_id');

        return [
            'user_id' => $context->userId !== null ? (string) $context->userId : null,
            'tenant_id' => $this->metadataString($context, $tenantKey) ?? $this->metadataString($context, 'tenant_id'),
            'workspace_id' => $this->metadataString($context, $workspaceKey) ?? $this->metadataString($context, 'workspace_id'),
            'session_id' => $context->sessionId,
        ];
    }

    private function metadataString(UnifiedActionContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function memoryPolicy(): ConversationMemoryPolicy
    {
        return $this->memoryPolicy ??= app(ConversationMemoryPolicy::class);
    }

    private function memoryExtractor(): ConversationMemoryExtractor
    {
        return $this->memoryExtractor ??= app(ConversationMemoryExtractor::class);
    }

    private function memoryRepository(): ConversationMemoryRepository
    {
        return $this->memoryRepository ??= app(ConversationMemoryRepository::class);
    }

    private function memorySemanticIndex(): ConversationMemorySemanticIndex
    {
        return $this->memorySemanticIndex ??= app(ConversationMemorySemanticIndex::class);
    }

    private function config(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
