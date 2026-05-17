<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\DTOs\AgentIntentDecision;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Localization\LocaleResourceService;

class AgentIntentUnderstandingService
{
    public function __construct(
        protected AIEngineService $ai,
        protected ?LocaleResourceService $localeResources = null,
    ) {
    }

    public function decide(string $message, ?UnifiedActionContext $context = null, array $options = []): AgentIntentDecision
    {
        $mode = strtolower((string) config('ai-agent.intent_understanding.mode', 'heuristic'));
        if ($mode === 'heuristic') {
            return $this->fallbackDecision($message);
        }

        $cacheKey = 'ai-agent:intent:' . sha1(json_encode([
            'message' => $message,
            'locale' => app()->getLocale(),
            'session' => $context?->sessionId,
            'mode' => $mode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $message);

        return Cache::remember($cacheKey, $this->cacheTtl(), function () use ($message, $context, $options): AgentIntentDecision {
            try {
                $response = $this->ai->generate(new AIRequest(
                    prompt: $this->prompt($message, $context),
                    engine: $options['engine'] ?? config('ai-agent.intent_understanding.engine', config('ai-engine.default')),
                    model: $options['model'] ?? config('ai-agent.intent_understanding.model', config('ai-engine.default_model')),
                    maxTokens: (int) config('ai-agent.intent_understanding.max_tokens', 500),
                    temperature: (float) config('ai-agent.intent_understanding.temperature', 0.0),
                    metadata: ['context' => 'agent_intent_understanding']
                ));

                if ($response->isSuccessful()) {
                    $decoded = $this->decodeJson($response->getContent());
                    if (is_array($decoded)) {
                        return AgentIntentDecision::fromArray($decoded);
                    }
                }
            } catch (\Throwable) {
                // Fall through to deterministic locale/config fallback.
            }

            return (bool) config('ai-agent.intent_understanding.fallback_to_heuristics', true)
                ? $this->fallbackDecision($message)
                : AgentIntentDecision::fromArray(['intent' => 'unknown', 'reason' => 'AI intent understanding unavailable.']);
        });
    }

    protected function fallbackDecision(string $message): AgentIntentDecision
    {
        $locale = $this->locale();

        if ($locale->containsLexicon($message, 'relation.use_existing')) {
            return AgentIntentDecision::fromArray([
                'route' => 'ask_ai',
                'mode' => 'action_flow',
                'intent' => 'choose_existing',
                'confidence' => 0.72,
                'reason' => 'Matched configured relation-use-existing lexicon.',
            ]);
        }

        if ($locale->containsLexicon($message, 'relation.create_new')) {
            return AgentIntentDecision::fromArray([
                'route' => 'ask_ai',
                'mode' => 'action_flow',
                'intent' => 'create_new',
                'confidence' => 0.72,
                'reason' => 'Matched configured relation-create-new lexicon.',
            ]);
        }

        if ($locale->containsLexicon($message, 'intent.deny')
            || $locale->containsLexicon($message, 'intent.reject')
            || $locale->containsLexicon($message, 'intent.cancel')) {
            return AgentIntentDecision::fromArray([
                'route' => 'ask_ai',
                'mode' => 'action_flow',
                'intent' => 'reject',
                'confidence' => 0.7,
                'reason' => 'Matched configured negative intent lexicon.',
            ]);
        }

        if ($locale->containsLexicon($message, 'intent.confirm')
            || $locale->containsLexicon($message, 'response.affirmative')) {
            return AgentIntentDecision::fromArray([
                'route' => 'ask_ai',
                'mode' => 'action_flow',
                'intent' => 'confirm',
                'confidence' => 0.7,
                'reason' => 'Matched configured affirmative intent lexicon.',
            ]);
        }

        return AgentIntentDecision::fromArray(['intent' => 'unknown', 'reason' => 'No configured intent signal matched.']);
    }

    protected function prompt(string $message, ?UnifiedActionContext $context): string
    {
        return implode("\n", [
            'Classify the user intent for a Laravel AI agent runtime.',
            'Return JSON only with keys: route, mode, intent, confidence, target, reason, metadata.',
            'Allowed intents: ' . implode(', ', AgentIntentDecision::allowedIntents()),
            'Do not rely on English trigger words; infer meaning in any language.',
            'Use recent history only as context. Do not execute actions.',
            'Recent history: ' . json_encode(array_slice($context?->conversationHistory ?? [], -6), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Latest message: ' . $message,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJson(string $content): ?array
    {
        $content = trim(preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content)) ?? $content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    protected function cacheTtl(): int
    {
        return max(0, (int) config('ai-agent.intent_understanding.cache_ttl_seconds', 120));
    }

    protected function locale(): LocaleResourceService
    {
        return $this->localeResources ??= app(LocaleResourceService::class);
    }
}
