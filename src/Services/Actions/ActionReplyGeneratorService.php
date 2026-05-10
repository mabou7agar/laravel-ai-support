<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Actions;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class ActionReplyGeneratorService
{
    public function __construct(private readonly AIEngineService $ai)
    {
    }

    /**
     * @param  array<string, mixed>  $workflowResult
     * @param  array{enhancer?: callable|null, ai_enabled?: bool|null}  $options
     * @return array{text: string, metadata: array<string, mixed>}
     */
    public function generate(array $workflowResult, array $options = []): array
    {
        $brief = $this->brief($workflowResult);
        $preserveTerms = $this->preserveTerms($workflowResult);

        $enhanced = $this->generateWithEnhancer(
            $brief,
            $preserveTerms,
            $workflowResult,
            $options['enhancer'] ?? $this->configuredEnhancer()
        );
        if (is_array($enhanced)) {
            return $enhanced;
        }

        $aiText = $this->generateWithAi($brief, $preserveTerms, $options['ai_enabled'] ?? null);
        if (is_string($aiText) && trim($aiText) !== '') {
            return [
                'text' => $aiText,
                'metadata' => [
                    'workflow_reply_provider' => 'ai',
                    'workflow_reply_generated' => true,
                ],
            ];
        }

        return [
            'text' => $this->emergencyReply($workflowResult),
            'metadata' => [
                'workflow_reply_provider' => 'emergency_fallback',
                'workflow_reply_generated' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<int, string>  $preserveTerms
     * @param  array<string, mixed>  $workflowResult
     * @return array{text: string, metadata: array<string, mixed>}|null
     */
    private function generateWithEnhancer(
        array $brief,
        array $preserveTerms,
        array $workflowResult,
        mixed $enhancer
    ): ?array {
        if (!is_callable($enhancer)) {
            return null;
        }

        try {
            $result = $enhancer($this->humanizerPrompt($brief), [
                'style' => 'workflow_reply',
                'preserve_terms' => $preserveTerms,
                'workflow_result' => $workflowResult,
                'brief' => $brief,
            ]);
        } catch (Throwable) {
            return null;
        }

        $text = is_array($result) ? ($result['text'] ?? null) : $result;
        if (!is_string($text) || trim($text) === '') {
            return null;
        }

        $metadata = is_array($result) && is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $provider = is_array($result) && is_string($result['provider'] ?? null) ? $result['provider'] : 'enhancer';

        return [
            'text' => $text,
            'metadata' => [
                'workflow_reply_provider' => $provider,
                'workflow_reply_generated' => true,
            ] + $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @param  array<int, string>  $preserveTerms
     */
    private function generateWithAi(array $brief, array $preserveTerms, ?bool $enabledOverride = null): ?string
    {
        if (!$this->aiReplyEnabled($enabledOverride)) {
            return null;
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->aiPrompt($brief),
                maxTokens: 180,
                temperature: 0.45,
                metadata: ['purpose' => 'agent_workflow_reply']
            ));

            if (!$response->isSuccessful()) {
                return null;
            }

            return $this->cleanAiReply($response->getContent(), $preserveTerms);
        } catch (Throwable) {
            return null;
        }
    }

    private function aiReplyEnabled(?bool $enabledOverride = null): bool
    {
        if ($enabledOverride === false || ($enabledOverride === null && !(bool) config('ai-agent.workflow_reply.ai_enabled', true))) {
            return false;
        }

        $engine = (string) config('ai-engine.default', config('ai-engine.default_engine', 'openai'));
        if (in_array($engine, ['ollama'], true)) {
            return true;
        }

        $apiKey = config("ai-engine.engines.{$engine}.api_key");
        if (!is_string($apiKey)) {
            return true;
        }

        $apiKey = trim($apiKey);

        return $apiKey !== '' && !in_array($apiKey, ['not-configured', 'your-api-key', 'your_api_key'], true);
    }

    private function configuredEnhancer(): mixed
    {
        $enhancer = config('ai-agent.workflow_reply.enhancer');
        if (is_callable($enhancer)) {
            return $enhancer;
        }

        if (!is_string($enhancer) || trim($enhancer) === '' || !function_exists('app')) {
            return null;
        }

        try {
            $resolved = app($enhancer);

            return is_callable($resolved) ? $resolved : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $workflowResult */
    private function emergencyReply(array $workflowResult): string
    {
        $message = ($workflowResult['success'] ?? false) === true && is_string($workflowResult['message'] ?? null)
            ? trim((string) $workflowResult['message'])
            : '';

        if ($message === '' && is_string($workflowResult['error'] ?? null) && trim((string) $workflowResult['error']) !== '') {
            $message = trim((string) $workflowResult['error']);
        }

        if ($message !== '') {
            return $message;
        }

        if (($workflowResult['success'] ?? false) === true) {
            return $this->message('Done.');
        }

        return $this->message('I need a little more information to continue.');
    }

    /**
     * @param  array<string, mixed>  $workflowResult
     * @return array<string, mixed>
     */
    private function brief(array $workflowResult): array
    {
        $relationNextSteps = $this->relationNextSteps((array) ($workflowResult['next_options'] ?? []));

        return [
            'status' => ($workflowResult['success'] ?? false) ? 'success' : 'needs_more_information',
            'needs_user_input' => (bool) ($workflowResult['needs_user_input'] ?? false),
            'message' => is_string($workflowResult['message'] ?? null) ? $workflowResult['message'] : null,
            'error' => is_string($workflowResult['error'] ?? null) ? $workflowResult['error'] : null,
            'missing_fields' => $this->shouldFocusRelationStep($relationNextSteps)
                ? []
                : collect($workflowResult['missing_fields'] ?? [])
                ->map(fn (string $field): string => $this->fieldLabel($field))
                ->values()
                ->all(),
            'relation_next_steps' => $relationNextSteps,
            'draft_summary' => $workflowResult['draft']['summary'] ?? null,
            'current_values' => $workflowResult['current_payload'] ?? ($workflowResult['draft']['payload'] ?? null),
            'relation_review' => $this->relationReviewBrief((array) ($workflowResult['relation_review'] ?? [])),
            'action' => $workflowResult['action'] ?? null,
        ];
    }

    /** @param array<int, array<string, mixed>> $relationNextSteps */
    private function shouldFocusRelationStep(array $relationNextSteps): bool
    {
        return collect($relationNextSteps)->contains(
            fn (array $step): bool => ($step['type'] ?? null) === 'relation_create_confirmation'
        );
    }

    /**
     * @param  array<int, mixed>  $options
     * @return array<int, array<string, mixed>>
     */
    private function relationNextSteps(array $options): array
    {
        return collect($options)
            ->filter(fn (mixed $option): bool => is_array($option))
            ->map(fn (array $option): array => [
                'type' => $option['type'] ?? null,
                'relation_type' => $option['relation_type'] ?? null,
                'label' => $option['label'] ?? null,
                'missing_required_fields' => collect($option['required_fields'] ?? [])
                    ->map(fn (string $field): string => $this->fieldLabel($field))
                    ->values()
                    ->all(),
                'approval_key' => $option['approval_key'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $relationReview
     * @return array<string, mixed>|null
     */
    private function relationReviewBrief(array $relationReview): ?array
    {
        $pendingCreates = collect($relationReview['pending_creates'] ?? [])
            ->filter(fn (mixed $relation): bool => is_array($relation))
            ->map(fn (array $relation): array => [
                'relation_type' => $relation['relation_type'] ?? null,
                'label' => $relation['label'] ?? null,
                'approved' => (bool) ($relation['approved'] ?? false),
                'missing_required_fields' => collect($relation['required_fields'] ?? [])
                    ->map(fn (string $field): string => $this->fieldLabel($field))
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        if ($pendingCreates === []) {
            return null;
        }

        return [
            'pending_creates' => $pendingCreates,
        ];
    }

    /** @param array<string, mixed> $brief */
    private function humanizerPrompt(array $brief): string
    {
        return implode("\n", [
            'Generate one concise user-facing assistant reply from these workflow facts.',
            'Rules:',
            '- Do not expose internal words like next_options, relation_review, payload, approval_key, tool, or strategy.',
            '- Do not ask again for facts already present in the workflow facts.',
            '- If a missing relation has some missing_required_fields, ask only for those fields.',
            '- If relation_next_steps is not empty, ask only for those relation steps; do not ask for fields that are not listed in missing_required_fields.',
            '- A relation_create_confirmation is approval for that related record only, not final confirmation for the main action.',
            '- Do not ask for any field that is already present in current_values.',
            '- Use short bullet points when the reply includes several fields, totals, or records.',
            '- Before asking the user to create/confirm the final action, summarize the draft and any related records that will be created.',
            '- Preserve names, emails, dates, amounts, IDs, SKUs, and quoted labels exactly.',
            '- Keep it short and natural, like a human teammate continuing the chat.',
            '- Avoid sounding like a fixed template; choose wording freely while keeping the next required step clear.',
            '',
            'Workflow facts JSON:',
            json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }

    /** @param array<string, mixed> $brief */
    private function aiPrompt(array $brief): string
    {
        return implode("\n", [
            'AGENT_WORKFLOW_REPLY',
            'Write the final assistant reply for this workflow state.',
            'Use the workflow facts as the only source of truth.',
            'Phrase the answer naturally, like a human teammate continuing the chat; do not copy system status or validation wording.',
            'Use short bullet points when the reply includes several fields, totals, or records.',
            'Before asking the user to create/confirm the final action, summarize the draft and any related records that will be created.',
            'Do not expose internal words like next_options, relation_review, payload, approval_key, tool, strategy, or workflow facts.',
            'Do not ask for data already present. If a relation only misses email, ask only for email.',
            'If relation_next_steps is not empty, ask only for those relation steps; do not ask for fields that are not listed in missing_required_fields.',
            'A relation_create_confirmation is approval for that related record only, not final confirmation for the main action.',
            'Do not ask for any field that is already present in current_values.',
            'Avoid sounding like a fixed template; choose wording freely while keeping the next required step clear.',
            'Preserve names, emails, dates, amounts, IDs, SKUs, and quoted labels exactly.',
            'Return only the reply text. No JSON. No markdown table.',
            '',
            'Workflow facts JSON:',
            json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        ]);
    }

    /** @param array<int, string> $preserveTerms */
    private function cleanAiReply(string $text, array $preserveTerms = []): ?string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:text|json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B\"'");

        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            foreach (['reply', 'message', 'text'] as $key) {
                if (is_string($decoded[$key] ?? null) && trim($decoded[$key]) !== '') {
                    $text = trim($decoded[$key]);
                    break;
                }
            }
        }

        return $this->restoreQuotedTerms($text, $preserveTerms);
    }

    /** @param array<int, string> $preserveTerms */
    private function restoreQuotedTerms(string $text, array $preserveTerms): string
    {
        foreach ($preserveTerms as $term) {
            $term = trim((string) $term);
            if ($term === '' || preg_match('/[.!?]$/u', $term) === 1) {
                continue;
            }

            foreach (['.', '!', '?'] as $punctuation) {
                $text = str_replace('"' . $term . $punctuation . '"', '"' . $term . '"' . $punctuation, $text);
                $text = str_replace('“' . $term . $punctuation . '”', '“' . $term . '”' . $punctuation, $text);
            }
        }

        return $text;
    }

    private function fieldLabel(string $field): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(
            ['.*.', '.0.', '_id', '_', '.'],
            [' item ', ' item ', '', ' ', ' '],
            $field
        )));
    }

    /**
     * @param  array<string, mixed>  $workflowResult
     * @return array<int, string>
     */
    private function preserveTerms(array $workflowResult): array
    {
        $terms = [];
        array_walk_recursive($workflowResult, function (mixed $value) use (&$terms): void {
            if (!is_string($value)) {
                return;
            }

            $value = trim($value);
            if ($value !== '' && (
                preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value) === 1
                || preg_match('/[A-Z]{2,}[-A-Z0-9]*\d/i', $value) === 1
                || mb_strlen($value) <= 80
            )) {
                $terms[] = $value;
            }
        });

        return array_values(array_unique($terms));
    }

    /** @param array<string, string> $replace */
    private function message(string $message, array $replace = []): string
    {
        if (function_exists('__')) {
            return __($message, $replace);
        }

        foreach ($replace as $key => $value) {
            $message = str_replace(':' . $key, $value, $message);
        }

        return $message;
    }
}
