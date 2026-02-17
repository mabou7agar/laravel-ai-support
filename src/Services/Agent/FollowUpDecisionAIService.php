<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;

class FollowUpDecisionAIService
{
    public const CLASS_FOLLOW_UP_ANSWER = 'FOLLOW_UP_ANSWER';
    public const CLASS_REFRESH_LIST = 'REFRESH_LIST';
    public const CLASS_ENTITY_LOOKUP = 'ENTITY_LOOKUP';
    public const CLASS_NEW_QUERY = 'NEW_QUERY';
    public const CLASS_UNKNOWN = 'UNKNOWN';

    public function __construct(
        protected ?AIEngineService $ai = null,
        protected ?IntentClassifierService $intentClassifier = null,
        protected ?DecisionPolicyService $decisionPolicy = null,
        protected ?FollowUpStateService $followUpState = null,
        protected array $settings = []
    ) {
    }

    public function shouldPreferFollowUpAnswer(string $message, UnifiedActionContext $context): bool
    {
        return $this->classify($message, $context) === $this->classLabel('follow_up_answer');
    }

    public function isEntityLookupClassification(string $classification): bool
    {
        return strtoupper(trim($classification)) === $this->classLabel('entity_lookup');
    }

    public function applyGuard(
        array $decision,
        string $message,
        UnifiedActionContext $context,
        array $options = []
    ): array {
        if (($decision['action'] ?? null) !== 'search_rag') {
            return $decision;
        }

        $classification = $options['followup_guard_classification'] ?? $this->classify($message, $context);
        if ($classification !== $this->classLabel('follow_up_answer')) {
            return $decision;
        }

        $decision['action'] = 'conversational';
        $decision['resource_name'] = null;
        $decision['reasoning'] = 'AI follow-up guard: answer from previous result context without re-listing';

        return $decision;
    }

    public function classify(string $message, UnifiedActionContext $context): string
    {
        if (!$this->getFollowUpState()->hasEntityListContext($context)) {
            return $this->classLabel('new_query');
        }

        if (!$this->isAIEnabled()) {
            return $this->useRulesFallbackWhenAIDisabled()
                ? $this->classifyWithRules($message, $context)
                : $this->classLabel('unknown');
        }

        try {
            $request = new AIRequest(
                prompt: $this->buildPrompt($message, $context),
                engine: $this->resolveEngine(),
                model: $this->resolveModel(),
                maxTokens: (int) ($this->settings['max_tokens'] ?? 64),
                temperature: (float) ($this->settings['temperature'] ?? 0.0)
            );

            $response = $this->getAI()->generate($request);
            $classification = $this->parseClassification($response->getContent());

            if ($classification !== $this->classLabel('unknown')) {
                return $classification;
            }

            Log::channel('ai-engine')->debug('FollowUpDecisionAIService: unknown AI classification', [
                'raw_response' => substr((string) $response->getContent(), 0, 180),
                'rules_fallback_on_ai_failure' => $this->useRulesFallbackOnAIFailure(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('ai-engine')->warning('FollowUpDecisionAIService: AI classification failed', [
                'error' => $e->getMessage(),
                'rules_fallback_on_ai_failure' => $this->useRulesFallbackOnAIFailure(),
            ]);
        }

        if ($this->useRulesFallbackOnAIFailure()) {
            return $this->classifyWithRules($message, $context);
        }

        return $this->classLabel('unknown');
    }

    protected function classifyWithRules(string $message, UnifiedActionContext $context): string
    {
        $signals = $this->getIntentClassifier()->classify(
            $message,
            $context,
            $this->getFollowUpState()->hasEntityListContext($context)
        );

        if ($this->getDecisionPolicy()->shouldPreferFollowUpAnswer($signals)) {
            return $this->classLabel('follow_up_answer');
        }

        if ($signals['is_explicit_list_request'] ?? false) {
            return $this->classLabel('refresh_list');
        }

        if ($signals['is_explicit_entity_lookup'] ?? false || $signals['is_positional_reference'] ?? false) {
            return $this->classLabel('entity_lookup');
        }

        return $this->classLabel('new_query');
    }

    protected function parseClassification(string $content): string
    {
        $responseKey = preg_quote($this->responseKey(), '/');
        if (preg_match('/' . $responseKey . ':\s*([A-Z_]+)/i', $content, $matches)) {
            return $this->normalizeClassification($matches[1]);
        }

        $line = trim((string) strtok($content, "\n"));
        if (str_contains($line, ':')) {
            $parts = explode(':', $line, 2);
            $line = trim((string) ($parts[1] ?? ''));
        }

        $line = strtoupper($line);
        return $this->normalizeClassification($line);
    }

    protected function normalizeClassification(string $value): string
    {
        $normalized = strtoupper(trim($value));
        $allowed = array_values($this->classMap());

        return in_array($normalized, $allowed, true) ? $normalized : $this->classLabel('unknown');
    }

    protected function buildPrompt(string $message, UnifiedActionContext $context): string
    {
        $listContext = $this->getFollowUpState()->formatEntityListContext($context);
        $history = $this->formatRecentHistory($context, (int) ($this->settings['history_window'] ?? 4));
        $followUpAnswer = $this->classLabel('follow_up_answer');
        $refreshList = $this->classLabel('refresh_list');
        $entityLookup = $this->classLabel('entity_lookup');
        $newQuery = $this->classLabel('new_query');
        $responseKey = $this->responseKey();

        return <<<PROMPT
You are classifying a user message after the assistant already returned a result list.

Previous Result Context:
{$listContext}

Recent Conversation:
{$history}

New User Message:
"{$message}"

Choose exactly one classification:
- {$followUpAnswer}: user asks a question about the previously listed results; answer from existing context, do not re-list.
- {$refreshList}: user explicitly asks to list/search/show results again.
- {$entityLookup}: user asks for a specific record/item (id, number, first/second/etc, open details).
- {$newQuery}: user changed topic and is not asking about previous listed results.

Respond with:
{$responseKey}: <one label only>
PROMPT;
    }

    protected function classMap(): array
    {
        $defaults = [
            'follow_up_answer' => self::CLASS_FOLLOW_UP_ANSWER,
            'refresh_list' => self::CLASS_REFRESH_LIST,
            'entity_lookup' => self::CLASS_ENTITY_LOOKUP,
            'new_query' => self::CLASS_NEW_QUERY,
            'unknown' => self::CLASS_UNKNOWN,
        ];

        $configured = $this->protocol('classes', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $merged = array_merge($defaults, $configured);
        foreach ($merged as $key => $value) {
            $merged[$key] = strtoupper(trim((string) $value));
        }

        return $merged;
    }

    protected function classLabel(string $key): string
    {
        $map = $this->classMap();
        return $map[$key] ?? self::CLASS_UNKNOWN;
    }

    protected function responseKey(): string
    {
        $key = (string) $this->protocol('response_key', 'CLASSIFICATION');
        return strtoupper(trim($key)) !== '' ? strtoupper(trim($key)) : 'CLASSIFICATION';
    }

    protected function protocol(string $key, $default = null)
    {
        if (isset($this->settings['protocol']) && is_array($this->settings['protocol'])) {
            $value = data_get($this->settings['protocol'], $key);
            if ($value !== null) {
                return $value;
            }
        }

        try {
            return config("ai-agent.protocol.followup_guard.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    protected function formatRecentHistory(UnifiedActionContext $context, int $window): string
    {
        $messages = array_slice($context->conversationHistory ?? [], -1 * max(1, $window));
        if (empty($messages)) {
            return '(none)';
        }

        $lines = [];
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'unknown';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $lines[] = "{$role}: " . mb_substr($content, 0, 240);
        }

        return empty($lines) ? '(none)' : implode("\n", $lines);
    }

    protected function isAIEnabled(): bool
    {
        return (bool) ($this->settings['enabled'] ?? true);
    }

    protected function useRulesFallbackOnAIFailure(): bool
    {
        return (bool) ($this->settings['rules_fallback_on_ai_failure'] ?? false);
    }

    protected function useRulesFallbackWhenAIDisabled(): bool
    {
        return (bool) ($this->settings['rules_fallback_when_ai_disabled'] ?? true);
    }

    protected function resolveEngine(): EngineEnum
    {
        $engine = (string) ($this->settings['engine'] ?? config('ai-engine.default', 'openai'));
        return EngineEnum::from($engine);
    }

    protected function resolveModel(): EntityEnum
    {
        $model = (string) ($this->settings['model'] ?? config('ai-engine.orchestration_model', 'gpt-4o-mini'));
        return EntityEnum::from($model);
    }

    protected function getAI(): AIEngineService
    {
        if ($this->ai === null) {
            $this->ai = app(AIEngineService::class);
        }

        return $this->ai;
    }

    protected function getIntentClassifier(): IntentClassifierService
    {
        if ($this->intentClassifier === null) {
            $this->intentClassifier = app(IntentClassifierService::class);
        }

        return $this->intentClassifier;
    }

    protected function getDecisionPolicy(): DecisionPolicyService
    {
        if ($this->decisionPolicy === null) {
            $this->decisionPolicy = app(DecisionPolicyService::class);
        }

        return $this->decisionPolicy;
    }

    protected function getFollowUpState(): FollowUpStateService
    {
        if ($this->followUpState === null) {
            $this->followUpState = app(FollowUpStateService::class);
        }

        return $this->followUpState;
    }
}
