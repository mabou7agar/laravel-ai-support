<?php

declare(strict_types=1);

namespace LaravelAIEngine\DTOs;

class AgentIntentDecision
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $route = 'ask_ai',
        public readonly string $mode = 'ambiguous',
        public readonly float $confidence = 0.0,
        public readonly string $intent = 'unknown',
        public readonly ?string $target = null,
        public readonly string $reason = '',
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            route: self::allowed((string) ($data['route'] ?? 'ask_ai'), ['ask_ai', 'search_rag', 'conversational'], 'ask_ai'),
            mode: trim((string) ($data['mode'] ?? 'ambiguous')) ?: 'ambiguous',
            confidence: min(1.0, max(0.0, (float) ($data['confidence'] ?? 0.0))),
            intent: self::allowed((string) ($data['intent'] ?? 'unknown'), self::allowedIntents(), 'unknown'),
            target: isset($data['target']) && $data['target'] !== '' ? (string) $data['target'] : null,
            reason: trim((string) ($data['reason'] ?? '')),
            metadata: is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
        );
    }

    /**
     * @return array<int, string>
     */
    public static function allowedIntents(): array
    {
        return [
            'chat',
            'action_request',
            'structured_query',
            'semantic_retrieval',
            'contextual_follow_up',
            'confirm',
            'reject',
            'choose_existing',
            'create_new',
            'continue_remote_session',
            'new_topic',
            'skill_request',
            'unknown',
        ];
    }

    /**
     * @param array<int, string> $allowed
     */
    private static function allowed(string $value, array $allowed, string $default): string
    {
        $value = trim($value);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
