<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\Services\AIEngineService;

class RAGResponseGenerator
{
    public function __construct(private readonly ?AIEngineService $ai = null) {}

    public function generate(string $prompt, array $context, array $options = []): AgentResponse
    {
        $message = $options['response'] ?? $this->generateAnswer($prompt, $options);

        $response = AgentResponse::success(
            message: $message,
            data: ['rag_context' => $context]
        );
        $response->metadata = [
            'sources' => $context['sources'] ?? [],
            'citations' => $context['citations'] ?? [],
            'rag_pipeline' => true,
            'rag_answer_generated' => $message !== $prompt,
        ];

        return $response;
    }

    private function generateAnswer(string $prompt, array $options): string
    {
        if (!$this->shouldGenerateAnswer($options) || $this->ai === null) {
            return $prompt;
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $prompt,
                engine: $options['engine'] ?? null,
                model: $options['model'] ?? null,
                userId: isset($options['user_id']) ? (string) $options['user_id'] : null,
                conversationId: isset($options['session_id']) ? (string) $options['session_id'] : null,
                systemPrompt: $options['system_prompt'] ?? 'Answer using only the retrieved context. If the context is insufficient, say what is missing.',
                maxTokens: isset($options['max_tokens']) ? (int) $options['max_tokens'] : 700,
                temperature: isset($options['temperature']) ? (float) $options['temperature'] : 0.1,
                metadata: [
                    'rag_pipeline' => true,
                    'purpose' => 'rag_answer_generation',
                ]
            ));

            return $response->isSuccessful() && trim($response->getContent()) !== ''
                ? $response->getContent()
                : $prompt;
        } catch (\Throwable) {
            return $prompt;
        }
    }

    private function shouldGenerateAnswer(array $options): bool
    {
        return (bool) (
            $options['generate_answer']
            ?? $options['rag_generate_answer']
            ?? config('ai-engine.rag.generate_answers', false)
        );
    }
}
