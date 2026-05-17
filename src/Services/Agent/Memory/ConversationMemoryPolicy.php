<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Memory;

class ConversationMemoryPolicy
{
    public function enabled(): bool
    {
        return (bool) config('ai-agent.conversation_memory.enabled', true);
    }

    public function extractOnCompaction(): bool
    {
        return (bool) config('ai-agent.conversation_memory.extract_on_compaction', true);
    }

    public function extractor(): string
    {
        return strtolower(trim((string) config('ai-agent.conversation_memory.extractor', 'ai')));
    }

    public function customExtractorClass(): ?string
    {
        $class = trim((string) config('ai-agent.conversation_memory.extractor_class', ''));

        return $class !== '' ? $class : null;
    }

    public function maxExtractionInputChars(): int
    {
        return max(500, (int) config('ai-agent.conversation_memory.max_extraction_input_chars', 6000));
    }

    public function maxMemoriesPerTurn(): int
    {
        return max(1, (int) config('ai-agent.conversation_memory.max_memories_per_turn', 6));
    }

    public function maxPromptChars(): int
    {
        return max(200, (int) config('ai-agent.conversation_memory.max_prompt_chars', 1200));
    }

    public function minScore(): float
    {
        return min(1.0, max(0.0, (float) config('ai-agent.conversation_memory.min_score', 0.45)));
    }

    public function ttlDays(): int
    {
        return max(0, (int) config('ai-agent.conversation_memory.ttl_days', 180));
    }

    public function engine(): ?string
    {
        $engine = trim((string) (config('ai-agent.conversation_memory.engine') ?: config('ai-engine.default')));

        return $engine !== '' ? $engine : null;
    }

    public function model(): ?string
    {
        $model = trim((string) (config('ai-agent.conversation_memory.model') ?: config('ai-engine.default_model')));

        return $model !== '' ? $model : null;
    }

    public function semanticEnabled(): bool
    {
        return (bool) config('ai-agent.conversation_memory.semantic.enabled', false);
    }

    public function semanticIndexOnWrite(): bool
    {
        return (bool) config('ai-agent.conversation_memory.semantic.index_on_write', false);
    }

    public function semanticDriver(): ?string
    {
        $driver = trim((string) config('ai-agent.conversation_memory.semantic.driver', ''));

        return $driver !== '' ? $driver : null;
    }

    public function semanticCollection(): string
    {
        return trim((string) config('ai-agent.conversation_memory.semantic.collection', 'ai_conversation_memories')) ?: 'ai_conversation_memories';
    }

    /**
     * @return array<int, string>
     */
    public function semanticPayloadScopeFields(): array
    {
        return array_values(array_filter(
            array_map('strval', (array) config('ai-agent.conversation_memory.semantic.payload_scope_fields', [])),
            static fn (string $field): bool => trim($field) !== ''
        ));
    }
}
