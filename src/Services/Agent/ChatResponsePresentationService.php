<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class ChatResponsePresentationService
{
    public function __construct(
        protected ResponsePointExtractor $points,
        protected AgentResponseSuggestionService $suggestions
    ) {
    }

    public function apply(AIResponse $response, string $message, array $options = [], ?UnifiedActionContext $context = null): AIResponse
    {
        $format = $this->pointsFormat($options);
        $metadata = [];
        $content = $response->getContent();

        if ($format !== 'text') {
            $extracted = $this->points->extract($content);
            $metadata['response_points_format'] = $format;
            $metadata['response_points'] = $extracted['points'];
            $metadata['response_points_count'] = count($extracted['points']);
            $metadata['response_text_without_points'] = $extracted['text_without_points'];

            if ($format === 'array' && $extracted['points'] !== []) {
                $content = $extracted['text_without_points'];
            }
        }

        $suggestions = array_merge(
            $this->existingSuggestions($response->getMetadata()),
            $this->suggestions->suggest(
                message: $message,
                response: $response->getContent(),
                metadata: $response->getMetadata(),
                context: $context,
                options: $options
            )
        );

        if ($suggestions !== []) {
            $suggestions = $this->uniqueSuggestions($suggestions);
            $metadata['suggestions'] = $suggestions;
            $metadata['suggestions_count'] = count($suggestions);
        }

        $presented = $content === $response->getContent()
            ? $response
            : $response->withContent($content);

        return $metadata === [] ? $presented : $presented->withMetadata($metadata);
    }

    protected function pointsFormat(array $options): string
    {
        $format = strtolower(trim((string) ($options['response_points_format']
            ?? $options['points_format']
            ?? config('ai-agent.response_presentation.points_format', 'text'))));

        return in_array($format, ['text', 'array', 'both', 'none'], true)
            ? ($format === 'none' ? 'text' : $format)
            : 'text';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function existingSuggestions(array $metadata): array
    {
        return collect($metadata['suggestions'] ?? [])
            ->filter(fn (mixed $suggestion): bool => is_array($suggestion))
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    protected function uniqueSuggestions(array $suggestions): array
    {
        $seen = [];

        return collect($suggestions)
            ->filter(function (array $suggestion) use (&$seen): bool {
                $key = (string) ($suggestion['id'] ?? $suggestion['message'] ?? $suggestion['label'] ?? md5((string) json_encode($suggestion)));
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            })
            ->values()
            ->all();
    }
}
