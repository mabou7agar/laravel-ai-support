<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\ConversationContextOptions;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\ConversationContextMode;

class ConversationContextSynchronizer
{
    /**
     * @param array<string, mixed> $options
     */
    public function synchronize(UnifiedActionContext $context, array $options): ConversationContextOptions
    {
        $contextOptions = ConversationContextOptions::fromArray($options);

        if ($contextOptions->mode === ConversationContextMode::CLIENT_REPLAY) {
            $this->replaceFromClientHistory($context, $options['conversation_history'] ?? null);
        }

        $context->metadata['conversation_context'] = [
            'mode' => $contextOptions->mode->value,
            'scope_hash' => $contextOptions->scope !== null ? hash('sha256', $contextOptions->scope) : null,
        ];

        return $contextOptions;
    }

    private function replaceFromClientHistory(UnifiedActionContext $context, mixed $history): void
    {
        if (!is_array($history) || $history === []) {
            return;
        }

        $filtered = array_values(array_filter(
            $history,
            static fn (mixed $message): bool => is_array($message)
                && in_array($message['role'] ?? null, ['system', 'user', 'assistant', 'tool'], true)
                && array_key_exists('content', $message)
        ));

        $filtered = array_map(static function (array $message): array {
            if ($message['content'] === null) {
                $message['content'] = '';
            } elseif (is_array($message['content'])) {
                $message['content'] = implode(' ', array_filter(array_map(
                    static fn (mixed $part): string => is_array($part) && ($part['type'] ?? '') === 'text'
                        ? trim((string) ($part['text'] ?? ''))
                        : (is_string($part) ? trim($part) : ''),
                    $message['content']
                )));
            }

            return $message;
        }, $filtered);

        if ($filtered === []) {
            return;
        }

        $maxMessages = max(2, (int) config('ai-agent.context_compaction.max_messages', 12));
        $context->conversationHistory = array_slice($filtered, -$maxMessages);
    }
}
