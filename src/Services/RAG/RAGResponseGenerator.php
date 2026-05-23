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
        $message = array_key_exists('response', $options)
            ? (string) $options['response']
            : $this->generateAnswer($prompt, $options);

        $usedFallback = $message === null || trim($message) === '' || $message === $prompt;
        if ($usedFallback) {
            $message = $this->fallbackAnswer($context, $options);
        }

        $response = AgentResponse::success(
            message: $message,
            data: ['rag_context' => $context]
        );
        $response->metadata = [
            'sources' => $context['sources'] ?? [],
            'citations' => $context['citations'] ?? [],
            'rag_pipeline' => true,
            'rag_answer_generated' => !$usedFallback,
            'rag_answer_fallback' => $usedFallback,
        ];

        return $response;
    }

    private function generateAnswer(string $prompt, array $options): ?string
    {
        if (!$this->shouldGenerateAnswer($options) || $this->ai === null) {
            return null;
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
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fallbackAnswer(array $context, array $options): string
    {
        $sources = is_array($context['sources'] ?? null) ? $context['sources'] : [];
        $contextText = trim((string) ($context['context'] ?? ''));

        if ($sources === [] && $contextText === '') {
            return (string) (
                $options['empty_context_message']
                ?? config('ai-engine.rag.empty_context_message', "I couldn't find relevant context for that request.")
            );
        }

        $prefix = (string) (
            $options['context_fallback_message']
            ?? config('ai-engine.rag.context_fallback_message', 'I found relevant context, but answer generation is disabled. Retrieved context:')
        );

        return trim($prefix."\n\n".$contextText);
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
