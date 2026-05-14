<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

class RAGPromptBuilder
{
    public function build(string $query, string $context, array $options = []): string
    {
        $instructions = trim((string) ($options['search_instructions'] ?? 'Answer using the retrieved context and cite sources when useful.'));

        return trim($instructions . "\n\nContext:\n" . ($context === '' ? 'No retrieved context.' : $context) . "\n\nQuestion:\n" . $query);
    }
}
