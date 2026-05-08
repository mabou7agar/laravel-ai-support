<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;

class ConversationContextCompactor
{
    public function compact(UnifiedActionContext $context): void
    {
        if (!$this->enabled()) {
            $context->conversationHistory = $this->sanitizeMessages(
                array_slice($context->conversationHistory, -$this->maxMessages())
            );

            return;
        }

        $history = $this->sanitizeMessages($context->conversationHistory);
        $shouldCompact = count($history) > $this->maxMessages()
            || $this->historyChars($history) > $this->maxTotalChars();

        if (!$shouldCompact) {
            $context->conversationHistory = $history;
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
    }

    public function summaryForPrompt(UnifiedActionContext $context): string
    {
        return trim((string) ($context->metadata['conversation_summary'] ?? ''));
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

    private function config(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
