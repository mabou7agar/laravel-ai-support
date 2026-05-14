<?php

namespace LaravelAIEngine\Services\RAG;

class RAGToolExecutionService
{
    public function normalize(array $decision): array
    {
        $tool = trim((string) ($decision['tool'] ?? 'db_query'));
        if ($tool === '') {
            $tool = 'db_query';
        }

        return [
            'tool' => $tool,
            'parameters' => is_array($decision['parameters'] ?? null) ? $decision['parameters'] : [],
        ];
    }

    /**
     * @param  array<string, callable(array): array>  $handlers
     * @return array<string, mixed>
     */
    public function execute(array $decision, array $handlers, string $fallbackTool = 'db_query'): array
    {
        $normalized = $this->normalize($decision);
        $tool = $normalized['tool'];

        $handler = $handlers[$tool] ?? $handlers[$fallbackTool] ?? null;
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException("No callable handler registered for tool [{$tool}]");
        }

        return $handler($normalized);
    }
}
