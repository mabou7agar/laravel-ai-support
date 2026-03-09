<?php

namespace LaravelAIEngine\Services\Summary;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use LaravelAIEngine\Models\AIEntitySummary;

class EntitySummaryService
{
    protected ?bool $tableAvailable = null;

    /** @var array<string, string> */
    protected array $requestCache = [];

    public function summaryForDisplay(mixed $entity, ?string $locale = null): ?string
    {
        if (!$entity instanceof Model) {
            return null;
        }

        $source = $this->buildSourceText($entity);
        if ($source === '') {
            return null;
        }

        $summaryText = $this->buildSummaryText($source);
        if (!$this->isEnabled()) {
            return $summaryText;
        }

        $resolvedLocale = $this->resolveLocale($locale);
        $sourceHash = sha1($source);
        $cacheKey = $this->cacheKey($entity, $resolvedLocale, $sourceHash);

        if (isset($this->requestCache[$cacheKey])) {
            return $this->requestCache[$cacheKey];
        }

        if (!$this->hasSummaryTable()) {
            $this->requestCache[$cacheKey] = $summaryText;

            return $summaryText;
        }

        try {
            $existing = AIEntitySummary::query()
                ->where('summaryable_type', $entity->getMorphClass())
                ->where('summaryable_id', (string) $entity->getKey())
                ->where('locale', $resolvedLocale)
                ->where('source_hash', $sourceHash)
                ->where(function ($query) {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->first();

            if ($existing) {
                $this->requestCache[$cacheKey] = $existing->summary;

                return $existing->summary;
            }

            $ttlMinutes = max(1, (int) config('ai-engine.entity_summaries.ttl_minutes', 10080));

            $stored = AIEntitySummary::updateOrCreate(
                [
                    'summaryable_type' => $entity->getMorphClass(),
                    'summaryable_id' => (string) $entity->getKey(),
                    'locale' => $resolvedLocale,
                ],
                [
                    'summary' => $summaryText,
                    'source_hash' => $sourceHash,
                    'policy_version' => 'minimal-v1',
                    'generated_at' => now(),
                    'expires_at' => now()->addMinutes($ttlMinutes),
                ]
            );

            $this->requestCache[$cacheKey] = $stored->summary;

            return $stored->summary;
        } catch (\Throwable) {
            // Fail-safe: summaries must never break primary query responses.
            $this->requestCache[$cacheKey] = $summaryText;

            return $summaryText;
        }
    }

    protected function isEnabled(): bool
    {
        return (bool) config('ai-engine.entity_summaries.enabled', true);
    }

    protected function hasSummaryTable(): bool
    {
        if ($this->tableAvailable !== null) {
            return $this->tableAvailable;
        }

        try {
            $this->tableAvailable = Schema::hasTable('ai_entity_summaries');
        } catch (\Throwable) {
            $this->tableAvailable = false;
        }

        return $this->tableAvailable;
    }

    protected function resolveLocale(?string $locale): string
    {
        $resolved = trim((string) ($locale ?: app()->getLocale() ?: config('ai-engine.entity_summaries.default_locale', 'en')));

        return $resolved !== '' ? $resolved : 'en';
    }

    protected function cacheKey(Model $entity, string $locale, string $sourceHash): string
    {
        return implode('|', [
            $entity->getMorphClass(),
            (string) $entity->getKey(),
            $locale,
            $sourceHash,
        ]);
    }

    protected function buildSourceText(Model $entity): string
    {
        if (method_exists($entity, 'toAISummarySource')) {
            return trim((string) $entity->toAISummarySource());
        }

        if (method_exists($entity, 'toRAGSummary')) {
            return trim((string) $entity->toRAGSummary());
        }

        if (method_exists($entity, 'toRAGContent')) {
            return trim((string) $entity->toRAGContent());
        }

        if (method_exists($entity, '__toString')) {
            $stringValue = trim((string) $entity);
            if ($stringValue !== '' && $stringValue !== get_class($entity)) {
                return $stringValue;
            }
        }

        $data = method_exists($entity, 'toArray') ? $entity->toArray() : $entity->getAttributes();
        if (!is_array($data) || $data === []) {
            return '';
        }

        $preferred = ['name', 'title', 'subject', 'status', 'type', 'id'];
        $orderedKeys = array_values(array_unique(array_merge(
            array_values(array_filter($preferred, static fn (string $field): bool => array_key_exists($field, $data))),
            array_keys($data)
        )));

        $skip = ['password', 'remember_token', 'token', 'api_token', 'access_token', 'refresh_token', 'deleted_at', 'updated_at'];
        $parts = [];
        foreach ($orderedKeys as $field) {
            if (!is_string($field) || in_array($field, $skip, true)) {
                continue;
            }

            $value = $this->stringValue($data[$field] ?? null);
            if ($value === '') {
                continue;
            }

            $parts[] = $field . ': ' . $value;
            if (count($parts) >= 10) {
                break;
            }
        }

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    protected function buildSummaryText(string $source): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($source)));
        if ($text === '') {
            return '';
        }

        $maxChars = max(120, (int) config('ai-engine.entity_summaries.max_chars', 420));
        if (mb_strlen($text) > $maxChars) {
            return rtrim(mb_substr($text, 0, $maxChars - 3)) . '...';
        }

        return $text;
    }

    protected function stringValue(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof \Stringable) {
            return trim((string) $value);
        }

        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? trim($encoded) : '';
        }

        return '';
    }
}
