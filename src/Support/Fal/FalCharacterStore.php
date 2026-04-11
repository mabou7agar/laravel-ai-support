<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Fal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FalCharacterStore
{
    private const INDEX_KEY = 'ai-engine.fal.characters.index';
    private const LAST_KEY = 'ai-engine.fal.characters.last';

    public function save(array $character, ?string $alias = null): string
    {
        $resolvedAlias = $this->normalizeAlias($alias ?: (string) ($character['name'] ?? 'character'));

        $payload = array_merge($character, [
            'alias' => $resolvedAlias,
        ]);

        Cache::forever($this->characterKey($resolvedAlias), $payload);
        Cache::forever(self::LAST_KEY, $resolvedAlias);

        $index = Cache::get(self::INDEX_KEY, []);
        if (!is_array($index)) {
            $index = [];
        }

        $index[$resolvedAlias] = [
            'name' => $payload['name'] ?? $resolvedAlias,
            'frontal_image_url' => $payload['frontal_image_url'] ?? null,
        ];

        Cache::forever(self::INDEX_KEY, $index);

        return $resolvedAlias;
    }

    public function get(string $alias): ?array
    {
        $resolvedAlias = $this->normalizeAlias($alias);
        $character = Cache::get($this->characterKey($resolvedAlias));

        return is_array($character) ? $character : null;
    }

    public function getLast(): ?array
    {
        $alias = Cache::get(self::LAST_KEY);

        return is_string($alias) ? $this->get($alias) : null;
    }

    public function all(): array
    {
        $index = Cache::get(self::INDEX_KEY, []);

        return is_array($index) ? $index : [];
    }

    private function characterKey(string $alias): string
    {
        return 'ai-engine.fal.characters.' . $alias;
    }

    private function normalizeAlias(string $alias): string
    {
        $normalized = Str::slug($alias);

        if ($normalized !== '') {
            return $normalized;
        }

        return 'character-' . now()->format('YmdHis');
    }
}
