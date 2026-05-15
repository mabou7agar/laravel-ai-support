<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use LaravelAIEngine\DTOs\UnifiedActionContext;

class IntentAliasCacheService
{
    private const DEFAULT_TTL_DAYS = 30;

    public function rememberAction(
        string|int $scope,
        string $locale,
        string $phrase,
        string $actionId,
        float $confidence = 0.95,
        string $source = 'tool_decision'
    ): void {
        $this->remember($scope, $locale, $phrase, [
            'type' => 'action',
            'action_id' => $actionId,
        ], $confidence, $source);
    }

    public function rememberQuery(
        string|int $scope,
        string $locale,
        string $phrase,
        array $route,
        float $confidence = 0.9,
        string $source = 'tool_decision'
    ): void {
        $this->remember($scope, $locale, $phrase, [
            'type' => 'data_query',
            'model' => $route['model'] ?? null,
            'table' => $route['table'] ?? null,
        ], $confidence, $source);
    }

    public function remember(
        string|int $scope,
        string $locale,
        string $phrase,
        array $route,
        float $confidence = 0.9,
        string $source = 'tool_decision'
    ): void {
        $normalized = $this->normalize($phrase);
        if (!$this->isCacheablePhrase($normalized) || $confidence < 0.85) {
            return;
        }

        Cache::put($this->phraseKey($scope, $locale, $normalized), array_merge($route, [
            'confidence' => $confidence,
            'source' => $source,
            'locale' => $locale,
            'scope' => (string) $scope,
            'normalized_phrase' => $normalized,
            'last_verified_at' => now()->toIso8601String(),
        ]), now()->addDays($this->ttlDays()));
    }

    public function resolve(string|int $scope, string $locale, string $phrase): ?array
    {
        $normalized = $this->normalize($phrase);
        if ($normalized === '') {
            return null;
        }

        $alias = Cache::get($this->phraseKey($scope, $locale, $normalized));
        if (!is_array($alias) || (string) ($alias['scope'] ?? '') !== (string) $scope) {
            return null;
        }

        $hitsKey = $this->phraseKey($scope, $locale, $normalized) . ':hits';
        Cache::put($hitsKey, ((int) Cache::get($hitsKey, 0)) + 1, now()->addDays($this->ttlDays()));

        return $alias;
    }

    public function learningPhrase(UnifiedActionContext $context): ?string
    {
        foreach (array_reverse($context->conversationHistory ?? []) as $entry) {
            if (($entry['role'] ?? null) !== 'user') {
                continue;
            }

            $phrase = trim((string) ($entry['content'] ?? ''));
            if ($this->isCacheablePhrase($this->normalize($phrase))) {
                return $phrase;
            }
        }

        return null;
    }

    public function normalize(string $phrase): string
    {
        $phrase = Str::lower(strip_tags($phrase));
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? '';

        return trim($phrase);
    }

    public function isCacheablePhrase(string $phrase): bool
    {
        if ($phrase === '' || mb_strlen($phrase) < 3 || mb_strlen($phrase) > 120) {
            return false;
        }

        if (preg_match('/@|\b\d{4,}\b|https?:\/\//iu', $phrase) === 1) {
            return false;
        }

        if (preg_match('/^(yes|no|ok|okay|confirm|نعم|لا|ايوه|أيوه)$/iu', $phrase) === 1) {
            return false;
        }

        return str_word_count($phrase) <= 14;
    }

    private function phraseKey(string|int $scope, string $locale, string $normalized): string
    {
        return 'ai-agent:intent-alias:' . $scope . ':' . $locale . ':' . sha1($normalized);
    }

    private function ttlDays(): int
    {
        return (int) config('ai-agent.intent_alias_cache.ttl_days', self::DEFAULT_TTL_DAYS);
    }
}
