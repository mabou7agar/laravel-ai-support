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
     * @param array{citations?:array<int,array<string,mixed>>,provider_tools?:array<int,array<string,mixed>>} $providerOutputs
     *        SDK outputs that may carry artifacts: URL citations and provider tool
     *        results (code execution, web search/fetch, file search)
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
        public readonly bool $cancelled = false,
        public readonly array $providerOutputs = []
    ) {
    }

    public function hasPendingApprovals(): bool
    {
        return $this->pendingApprovals !== [];
    }

    public function hasProviderOutputs(): bool
    {
        return ($this->providerOutputs['citations'] ?? []) !== []
            || ($this->providerOutputs['provider_tools'] ?? []) !== [];
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
