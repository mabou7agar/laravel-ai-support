<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;

class AgentResponseSuggestionService
{
    public function __construct(
        protected AgentSkillRegistry $skills,
        protected ToolRegistry $tools,
        protected ActionFlowHandler $actions
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggest(
        string $message,
        string $response,
        array $metadata = [],
        ?UnifiedActionContext $context = null,
        array $options = []
    ): array {
        if (!$this->enabled($options)) {
            return [];
        }

        $haystack = $this->normalize($message . ' ' . $response . ' ' . json_encode($metadata));
        $suggestions = [];

        foreach ($this->actionSuggestions($haystack, $context) as $suggestion) {
            $suggestions[$suggestion['type'] . ':' . $suggestion['id']] = $suggestion;
        }

        foreach ($this->skillSuggestions($haystack) as $suggestion) {
            $suggestions[$suggestion['type'] . ':' . $suggestion['id']] = $suggestion;
        }

        foreach ($this->toolSuggestions($haystack) as $suggestion) {
            $suggestions[$suggestion['type'] . ':' . $suggestion['id']] = $suggestion;
        }

        foreach ($this->customActionSuggestions($message, $response, $metadata, $context) as $suggestion) {
            $suggestions[$suggestion['type'] . ':' . $suggestion['id']] = $suggestion;
        }

        usort($suggestions, static fn (array $a, array $b): int => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));

        return array_slice(array_values($suggestions), 0, $this->limit($options));
    }

    protected function enabled(array $options): bool
    {
        return (bool) ($options['response_suggestions']
            ?? $options['suggestions']
            ?? config('ai-agent.response_presentation.suggestions.enabled', true));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function actionSuggestions(string $haystack, ?UnifiedActionContext $context): array
    {
        $catalog = $this->actions->catalog($context);
        $actions = is_array($catalog['actions'] ?? null) ? $catalog['actions'] : [];
        $suggestions = [];

        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $score = $this->score($haystack, [
                $action['id'] ?? '',
                $action['label'] ?? '',
                $action['description'] ?? '',
                $action['operation'] ?? '',
                implode(' ', (array) ($action['required'] ?? [])),
                implode(' ', array_keys((array) ($action['parameters'] ?? []))),
            ]);

            if ($score <= 0) {
                continue;
            }

            $suggestions[] = [
                'type' => 'action',
                'id' => (string) ($action['id'] ?? ''),
                'label' => (string) ($action['label'] ?? $action['id'] ?? ''),
                'description' => (string) ($action['description'] ?? ''),
                'confidence' => $score,
                'requires_confirmation' => (bool) ($action['confirmation_required'] ?? true),
                'required' => array_values((array) ($action['required'] ?? [])),
                'parameters' => (array) ($action['parameters'] ?? []),
            ];
        }

        return $suggestions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function skillSuggestions(string $haystack): array
    {
        return array_values(array_filter(array_map(function (AgentSkillDefinition $skill) use ($haystack): ?array {
            $score = $this->score($haystack, [
                $skill->id,
                $skill->name,
                $skill->description,
                implode(' ', $skill->triggers),
                implode(' ', $skill->requiredData),
                implode(' ', $skill->capabilities),
            ]);

            if ($score <= 0) {
                return null;
            }

            return [
                'type' => 'skill',
                'id' => $skill->id,
                'label' => $skill->name,
                'description' => $skill->description,
                'confidence' => $score,
                'tools' => $skill->tools,
                'actions' => $skill->actions,
                'requires_confirmation' => $skill->requiresConfirmation,
            ];
        }, $this->skills->skills())));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function toolSuggestions(string $haystack): array
    {
        $suggestions = [];
        foreach ($this->tools->all() as $name => $tool) {
            if (!$tool instanceof AgentTool) {
                continue;
            }

            $score = $this->score($haystack, [
                $name,
                $tool->getName(),
                $tool->getDescription(),
                implode(' ', array_keys($tool->getParameters())),
            ]);

            if ($score <= 0) {
                continue;
            }

            $suggestions[] = [
                'type' => 'tool',
                'id' => (string) $name,
                'label' => $tool->getName(),
                'description' => $tool->getDescription(),
                'confidence' => $score,
                'requires_confirmation' => $tool->requiresConfirmation(),
                'parameters' => $tool->getParameters(),
            ];
        }

        return $suggestions;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function customActionSuggestions(
        string $message,
        string $response,
        array $metadata,
        ?UnifiedActionContext $context
    ): array {
        $result = $this->actions->suggest([
            'message' => $message,
            'response' => $response,
            'metadata' => $metadata,
        ], $context);

        return array_values(array_filter(array_map(function (array $suggestion): ?array {
            $id = (string) ($suggestion['action_id'] ?? $suggestion['id'] ?? '');
            if ($id === '') {
                return null;
            }

            return array_merge($suggestion, [
                'type' => (string) ($suggestion['type'] ?? 'action'),
                'id' => $id,
                'label' => (string) ($suggestion['label'] ?? $id),
                'confidence' => (float) ($suggestion['confidence'] ?? 1.0),
            ]);
        }, (array) ($result['suggestions'] ?? []))));
    }

    protected function score(string $haystack, array $fields): float
    {
        $needle = $this->normalize(implode(' ', array_filter(array_map('strval', $fields))));
        if ($needle === '') {
            return 0.0;
        }

        if (str_contains($haystack, $needle)) {
            return 1.0;
        }

        $haystackTokens = array_flip($this->tokens($haystack));
        $tokens = $this->tokens($needle);
        $matches = 0;

        foreach ($tokens as $token) {
            if (isset($haystackTokens[$token])) {
                $matches++;
            }
        }

        if ($matches === 0) {
            return 0.0;
        }

        return round(min(0.95, 0.45 + ($matches / max(1, min(6, count($tokens))))), 2);
    }

    protected function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\pL\pN_\/.-]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * @return array<int, string>
     */
    protected function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', $this->normalize($value)) ?: [];
        $stop = array_flip((array) config('ai-agent.response_presentation.suggestions.stop_words', [
            'the', 'a', 'an', 'and', 'or', 'to', 'for', 'from', 'with', 'this', 'that', 'current',
            'available', 'deterministic', 'data', 'user', 'message', 'response',
        ]));

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token) > 2 && !isset($stop[$token])
        )));
    }

    protected function limit(array $options): int
    {
        return max(0, min(25, (int) ($options['response_suggestion_limit']
            ?? config('ai-agent.response_presentation.suggestions.limit', 5))));
    }
}
