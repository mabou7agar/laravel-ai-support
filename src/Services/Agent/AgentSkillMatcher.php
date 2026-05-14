<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class AgentSkillMatcher
{
    public function __construct(
        private readonly AgentSkillRegistry $skills,
        private readonly ?AIEngineService $ai = null
    )
    {
    }

    /**
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    public function match(string $message, bool $includeDisabled = false): ?array
    {
        $message = trim($message);
        $normalizedMessage = $this->normalize($message);
        if ($normalizedMessage === '') {
            return null;
        }

        $best = null;

        foreach ($this->skills->skills(includeDisabled: $includeDisabled) as $skill) {
            $candidate = $this->scoreSkill($skill, $normalizedMessage);
            if ($candidate === null) {
                continue;
            }

            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    public function matchIntent(string $message, UnifiedActionContext $context, bool $includeDisabled = false): ?array
    {
        $deterministic = $this->match($message, $includeDisabled);
        if ($deterministic !== null) {
            return $deterministic;
        }

        $skills = $this->skills->skills(includeDisabled: $includeDisabled);
        if ($skills === []) {
            return null;
        }

        $contextual = $this->matchFromConversationContext($message, $context, $skills);
        if ($contextual !== null) {
            return $contextual;
        }

        $alias = $this->matchSemanticAliases($message, $context, $skills);
        if ($alias !== null) {
            return $alias;
        }

        if (!$this->intentMatchingEnabled() || !$this->ai instanceof AIEngineService) {
            return null;
        }

        try {
            $response = $this->ai->generate(new AIRequest(
                prompt: $this->intentPrompt($message, $context, $skills),
                engine: $this->intentConfig('engine', config('ai-engine.default', 'openai')),
                model: $this->intentConfig('model', config('ai-engine.orchestration_model', config('ai-engine.default_model', 'gpt-4o-mini'))),
                maxTokens: (int) $this->intentConfig('max_tokens', 450),
                temperature: (float) $this->intentConfig('temperature', 0.05),
                metadata: ['context' => 'agent_skill_intent_match']
            ));
        } catch (Throwable $exception) {
            Log::channel('ai-engine')->debug('Agent skill intent matching failed', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (!$response->isSuccessful()) {
            return null;
        }

        $decoded = $this->decodeJson($response->getContent());
        if (!is_array($decoded)) {
            return null;
        }

        $skillId = trim((string) ($decoded['skill_id'] ?? ''));
        $confidence = (int) round((float) ($decoded['confidence'] ?? 0));
        if ($skillId === '' || in_array(strtolower($skillId), ['none', 'null'], true) || $confidence < $this->intentThreshold()) {
            return null;
        }

        $skill = collect($skills)->first(fn (AgentSkillDefinition $candidate): bool => $candidate->id === $skillId);
        if (!$skill instanceof AgentSkillDefinition) {
            return null;
        }

        return [
            'skill' => $skill,
            'score' => min(99, max(1, $confidence)),
            'trigger' => 'ai_intent',
            'reason' => trim((string) ($decoded['reason'] ?? 'Matched skill by conversation intent.')) ?: 'Matched skill by conversation intent.',
        ];
    }

    /**
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    protected function scoreSkill(AgentSkillDefinition $skill, string $normalizedMessage): ?array
    {
        $bestScore = 0;
        $bestTrigger = '';

        foreach ($this->candidateTriggers($skill) as $trigger) {
            $normalizedTrigger = $this->normalize($trigger);
            if ($normalizedTrigger === '') {
                continue;
            }

            $score = 0;
            if ($normalizedMessage === $normalizedTrigger) {
                $score = 100;
            } elseif (str_starts_with($normalizedMessage, $normalizedTrigger . ' ')) {
                $score = 90;
            } elseif (preg_match('/\b' . preg_quote($normalizedTrigger, '/') . '\b/u', $normalizedMessage) === 1) {
                $score = 75;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestTrigger = $trigger;
            }
        }

        if ($bestScore === 0) {
            return null;
        }

        return [
            'skill' => $skill,
            'score' => $bestScore,
            'trigger' => $bestTrigger,
            'reason' => "Matched skill trigger [{$bestTrigger}].",
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function candidateTriggers(AgentSkillDefinition $skill): array
    {
        return array_values(array_unique(array_filter(array_merge(
            $skill->triggers,
            [$skill->name],
            $skill->capabilities
        ))));
    }

    protected function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    /**
     * @param array<int, AgentSkillDefinition> $skills
     */
    protected function intentPrompt(string $message, UnifiedActionContext $context, array $skills): string
    {
        return implode("\n", [
            'AGENT_SKILL_INTENT_MATCHER',
            'Select the one skill that should handle the latest user request, using the recent conversation as context.',
            'The latest message may be indirect, multilingual, or refer to earlier entities with words like this, it, him, her, them, order, quote, or conversation.',
            'Match by user intent and required output, not only by trigger words.',
            'If the recent conversation contains the data needed by a skill and the latest message asks to bill, create, submit, finalize, convert, turn it into something, or do it, choose that skill.',
            'Example: if the conversation discussed a customer and products, and the user says "bill him for those items" or "turn this into an invoice", choose an invoice-creation skill.',
            'Return no match when the user is only chatting, asking an unrelated question, or the skill would not perform the requested business action.',
            'Return JSON only. Do not explain. Do not wrap in markdown.',
            'JSON shape: {"skill_id":"skill id or null","confidence":0,"reason":"short reason"}',
            '',
            'Available skills JSON:',
            json_encode(array_map(fn (AgentSkillDefinition $skill): array => $this->skillIntentDocument($skill), $skills), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Recent conversation JSON:',
            json_encode(array_slice($context->conversationHistory, -8), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            '',
            'Latest user message:',
            $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function skillIntentDocument(AgentSkillDefinition $skill): array
    {
        return [
            'id' => $skill->id,
            'name' => $skill->name,
            'description' => $skill->description,
            'triggers' => $skill->triggers,
            'required_data' => $skill->requiredData,
            'actions' => $skill->actions,
            'tools' => $skill->tools,
            'capabilities' => $skill->capabilities,
            'target_json' => $skill->metadata['target_json'] ?? null,
        ];
    }

    /**
     * @param array<int, AgentSkillDefinition> $skills
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    protected function matchFromConversationContext(string $message, UnifiedActionContext $context, array $skills): ?array
    {
        if (!$this->looksLikeContinuationRequest($message)) {
            return null;
        }

        $historyText = collect(array_slice($context->conversationHistory, -8))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): string => (string) ($entry['content'] ?? ''))
            ->implode(' ');

        $normalized = $this->normalize(trim($historyText . ' ' . $message));
        if ($normalized === '') {
            return null;
        }

        $best = null;
        foreach ($skills as $skill) {
            $candidate = $this->scoreSkill($skill, $normalized);
            if ($candidate === null) {
                continue;
            }

            $candidate['score'] = min(85, $candidate['score']);
            $candidate['trigger'] = 'conversation_context:' . $candidate['trigger'];
            $candidate['reason'] = "Matched skill [{$skill->name}] from recent conversation context.";

            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    protected function looksLikeContinuationRequest(string $message): bool
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return false;
        }

        return preg_match('/\b(do it|create it|make it|submit it|finalize|proceed|go ahead|turn .* into|convert .* into|use .* conversation|from .* conversation|bill|charge|invoice|فاتورة|حول|اعمل|انشئ|أنشئ)\b/u', $normalized) === 1;
    }

    /**
     * @param array<int, AgentSkillDefinition> $skills
     * @return array{skill:AgentSkillDefinition,score:int,trigger:string,reason:string}|null
     */
    protected function matchSemanticAliases(string $message, UnifiedActionContext $context, array $skills): ?array
    {
        $combined = $this->normalize(trim($message . ' ' . collect(array_slice($context->conversationHistory, -4))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): string => (string) ($entry['content'] ?? ''))
            ->implode(' ')));

        if ($combined === '') {
            return null;
        }

        foreach ($this->semanticAliasMap() as $concept => $aliases) {
            if (!$this->containsAnyNormalized($combined, $aliases)) {
                continue;
            }

            foreach ($skills as $skill) {
                $skillText = $this->normalize(implode(' ', array_filter([
                    $skill->id,
                    $skill->name,
                    $skill->description,
                    implode(' ', $skill->capabilities),
                    implode(' ', $skill->actions),
                ])));

                if (!str_contains($skillText, $this->normalize((string) $concept))) {
                    continue;
                }

                return [
                    'skill' => $skill,
                    'score' => 82,
                    'trigger' => 'semantic_alias:' . $concept,
                    'reason' => "Matched skill [{$skill->name}] by semantic business alias [{$concept}].",
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function semanticAliasMap(): array
    {
        $configured = config('ai-agent.skills.intent_aliases');
        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return [
            'invoice' => ['invoice', 'bill', 'billing', 'charge', 'فاتورة', 'فوتر', 'facture', 'factura', 'fatura'],
        ];
    }

    /**
     * @param array<int, string> $needles
     */
    protected function containsAnyNormalized(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            $needle = $this->normalize((string) $needle);
            if ($needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function decodeJson(string $content): ?array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;

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

    protected function intentMatchingEnabled(): bool
    {
        return (bool) $this->intentConfig('enabled', true);
    }

    protected function intentThreshold(): int
    {
        return max(1, min(100, (int) $this->intentConfig('min_confidence', 72)));
    }

    protected function intentConfig(string $key, mixed $default = null): mixed
    {
        return config("ai-agent.skills.intent_matching.{$key}", $default);
    }
}
