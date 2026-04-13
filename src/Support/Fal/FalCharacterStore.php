<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Fal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FalCharacterStore
{
    private const INDEX_KEY = 'ai-engine.fal.characters.index';
    private const LAST_KEY = 'ai-engine.fal.characters.last';
    private const VOICE_KEYS = ['voice_id', 'stability', 'similarity_boost', 'style', 'use_speaker_boost'];

    public function save(array $character, ?string $alias = null): string
    {
        $resolvedAlias = $this->normalizeAlias($alias ?: (string) ($character['name'] ?? 'character'));

        $payload = array_merge($character, [
            'alias' => $resolvedAlias,
        ], $this->normalizeVoicePayload($character));

        Cache::forever($this->characterKey($resolvedAlias), $payload);
        Cache::forever(self::LAST_KEY, $resolvedAlias);

        $index = Cache::get(self::INDEX_KEY, []);
        if (!is_array($index)) {
            $index = [];
        }

        $index[$resolvedAlias] = [
            'name' => $payload['name'] ?? $resolvedAlias,
            'frontal_image_url' => $payload['frontal_image_url'] ?? null,
            'voice_id' => $payload['voice_id'] ?? null,
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

    public function voiceProfile(string $alias): ?array
    {
        $character = $this->get($alias);

        if (!is_array($character)) {
            return null;
        }

        return $this->extractVoiceProfile($character);
    }

    public function lastVoiceProfile(): ?array
    {
        $character = $this->getLast();

        if (!is_array($character)) {
            return null;
        }

        return $this->extractVoiceProfile($character);
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

    private function normalizeVoicePayload(array $character): array
    {
        $voiceProfile = $this->extractVoiceProfile($character);

        if ($voiceProfile === []) {
            return [];
        }

        $voiceSettings = [];
        foreach (['stability', 'similarity_boost', 'style', 'use_speaker_boost'] as $key) {
            if (array_key_exists($key, $voiceProfile)) {
                $voiceSettings[$key] = $voiceProfile[$key];
            }
        }

        $payload = [];
        if (isset($voiceProfile['voice_id'])) {
            $payload['voice_id'] = $voiceProfile['voice_id'];
        }
        if ($voiceSettings !== []) {
            $payload['voice_settings'] = $voiceSettings;
        }

        return $payload;
    }

    private function extractVoiceProfile(array $character): array
    {
        $profile = [];

        foreach (self::VOICE_KEYS as $key) {
            if (array_key_exists($key, $character) && $character[$key] !== null && $character[$key] !== '') {
                $profile[$key] = $character[$key];
            }
        }

        $voiceSettings = $character['voice_settings'] ?? null;
        if (is_array($voiceSettings)) {
            foreach (self::VOICE_KEYS as $key) {
                if ($key === 'voice_id') {
                    continue;
                }

                if (array_key_exists($key, $voiceSettings) && $voiceSettings[$key] !== null && $voiceSettings[$key] !== '') {
                    $profile[$key] = $voiceSettings[$key];
                }
            }
        }

        $metadata = $character['metadata'] ?? null;
        if (is_array($metadata)) {
            foreach (['voice', 'voice_settings'] as $metadataKey) {
                $candidate = $metadata[$metadataKey] ?? null;
                if (!is_array($candidate)) {
                    continue;
                }

                foreach (self::VOICE_KEYS as $key) {
                    if (array_key_exists($key, $candidate) && $candidate[$key] !== null && $candidate[$key] !== '') {
                        $profile[$key] = $candidate[$key];
                    }
                }
            }

            if (isset($metadata['voice_id']) && $metadata['voice_id'] !== '') {
                $profile['voice_id'] = $metadata['voice_id'];
            }
        }

        return $profile;
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
