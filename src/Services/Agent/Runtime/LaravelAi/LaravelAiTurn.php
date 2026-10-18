<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Runtime\LaravelAi;

/**
 * The SDK-independent result of one laravel/ai turn.
 */
final class LaravelAiTurn
{
    /**
     * @param array{input_tokens?:int,output_tokens?:int,cache_read_input_tokens?:int|null,cache_write_input_tokens?:int|null,reasoning_tokens?:int|null} $usage
     * @param array<int, array{id:string,name:string,arguments:array<string,mixed>}> $toolCalls
     * @param array<int, array{id:string,name:string,result:mixed,successful:bool,denied:bool}> $toolResults
     * @param array<int, array{id:string,tool:string,arguments:array<string,mixed>,reason:?string}> $pendingApprovals
     */
    public function __construct(
        public readonly string $text,
        public readonly array $usage = [],
        public readonly ?string $invocationId = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly array $toolCalls = [],
        public readonly array $toolResults = [],
        public readonly array $pendingApprovals = [],
        public readonly string $reasoning = '',
        public readonly bool $cancelled = false
    ) {
    }

    public function hasPendingApprovals(): bool
    {
        return $this->pendingApprovals !== [];
    }

    public function inputTokens(): int
    {
        return (int) ($this->usage['input_tokens'] ?? 0);
    }

    public function outputTokens(): int
    {
        return (int) ($this->usage['output_tokens'] ?? 0);
    }

    public function totalTokens(): int
    {
        return $this->inputTokens() + $this->outputTokens();
    }
}
