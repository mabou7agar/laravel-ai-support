<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Fal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FalCharacterStore
{
    private const TABLE = 'ai_reference_packs';
    private const INDEX_KEY = 'ai-engine.fal.characters.index';
    private const LAST_KEY = 'ai-engine.fal.characters.last';
    private const VOICE_KEYS = ['voice_id', 'stability', 'similarity_boost', 'style', 'use_speaker_boost'];
    private ?bool $referencePackTableExists = null;

    public function save(array $character, ?string $alias = null): string
    {
        $resolvedAlias = $this->normalizeAlias($alias ?: (string) ($character['name'] ?? 'character'));

        $payload = array_merge($character, [
            'alias' => $resolvedAlias,
        ], $this->normalizeVoicePayload($character));

        $this->storeInCache($payload);
        $this->storeInDatabase($payload);

        return $resolvedAlias;
    }

    public function get(string $alias): ?array
    {
        $resolvedAlias = $this->normalizeAlias($alias);
        $character = Cache::get($this->characterKey($resolvedAlias));

        if (is_array($character)) {
            return $character;
        }

        $character = $this->getFromDatabase($resolvedAlias);
        if ($character !== null) {
            $this->storeInCache($character);
        }

        return $character;
    }

    public function getLast(): ?array
    {
        $alias = Cache::get(self::LAST_KEY);

        if (is_string($alias)) {
            $character = $this->get($alias);
            if ($character !== null) {
                return $character;
            }
        }

        $character = $this->getLatestFromDatabase();
        if ($character !== null) {
            $this->storeInCache($character);
        }

        return $character;
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

        if (!$this->hasReferencePackTable() && is_array($index) && $index !== []) {
            return $index;
        }

        $databaseIndex = $this->allFromDatabase();
        if ($databaseIndex !== []) {
            Cache::forever(self::INDEX_KEY, $databaseIndex);

            return $databaseIndex;
        }

        $index = is_array($index) ? $index : [];
        if ($index !== []) {
            Cache::forever(self::INDEX_KEY, $index);
        }

        return $index;
    }

    private function characterKey(string $alias): string
    {
        return 'ai-engine.fal.characters.' . $alias;
    }

    private function storeInCache(array $payload): void
    {
        $alias = (string) ($payload['alias'] ?? '');
        if ($alias === '') {
            return;
        }

        Cache::forever($this->characterKey($alias), $payload);
        Cache::forever(self::LAST_KEY, $alias);

        $index = Cache::get(self::INDEX_KEY, []);
        if (!is_array($index)) {
            $index = [];
        }

        $index[$alias] = $this->buildIndexPayload($payload);

        Cache::forever(self::INDEX_KEY, $index);
    }

    private function storeInDatabase(array $payload): void
    {
        if (!$this->hasReferencePackTable()) {
            return;
        }

        try {
            $now = now();
            $record = [
                'alias' => (string) $payload['alias'],
                'name' => isset($payload['name']) ? (string) $payload['name'] : null,
                'entity_type' => data_get($payload, 'metadata.entity_type'),
                'frontal_image_url' => isset($payload['frontal_image_url']) ? (string) $payload['frontal_image_url'] : null,
                'frontal_provider_image_url' => isset($payload['frontal_provider_image_url']) ? (string) $payload['frontal_provider_image_url'] : null,
                'voice_id' => isset($payload['voice_id']) ? (string) $payload['voice_id'] : null,
                'payload' => json_encode($payload),
                'updated_at' => $now,
            ];

            $existing = DB::table(self::TABLE)
                ->where('alias', $payload['alias'])
                ->exists();

            if ($existing) {
                DB::table(self::TABLE)
                    ->where('alias', $payload['alias'])
                    ->update($record);

                return;
            }

            DB::table(self::TABLE)->insert($record + ['created_at' => $now]);
        } catch (\Throwable $e) {
            logger()->debug('Failed to persist AI reference pack in database', [
                'alias' => $payload['alias'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getFromDatabase(string $alias): ?array
    {
        if (!$this->hasReferencePackTable()) {
            return null;
        }

        try {
            $record = DB::table(self::TABLE)
                ->where('alias', $alias)
                ->first();

            return $this->payloadFromDatabaseRecord($record);
        } catch (\Throwable $e) {
            logger()->debug('Failed to fetch AI reference pack from database', [
                'alias' => $alias,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function getLatestFromDatabase(): ?array
    {
        if (!$this->hasReferencePackTable()) {
            return null;
        }

        try {
            $record = DB::table(self::TABLE)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            return $this->payloadFromDatabaseRecord($record);
        } catch (\Throwable $e) {
            logger()->debug('Failed to fetch latest AI reference pack from database', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function allFromDatabase(): array
    {
        if (!$this->hasReferencePackTable()) {
            return [];
        }

        try {
            $records = DB::table(self::TABLE)
                ->orderBy('name')
                ->orderBy('alias')
                ->get();

            $index = [];
            foreach ($records as $record) {
                $payload = $this->payloadFromDatabaseRecord($record);
                if ($payload === null) {
                    continue;
                }

                $index[(string) $payload['alias']] = $this->buildIndexPayload($payload);
            }

            return $index;
        } catch (\Throwable $e) {
            logger()->debug('Failed to list AI reference packs from database', [
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    private function payloadFromDatabaseRecord(object|null $record): ?array
    {
        if ($record === null) {
            return null;
        }

        $payload = json_decode((string) ($record->payload ?? ''), true);
        if (!is_array($payload)) {
            return null;
        }

        $payload['alias'] = $payload['alias'] ?? $record->alias;

        return $payload;
    }

    private function buildIndexPayload(array $payload): array
    {
        return [
            'name' => $payload['name'] ?? ($payload['alias'] ?? 'character'),
            'frontal_image_url' => $payload['frontal_image_url'] ?? null,
            'voice_id' => $payload['voice_id'] ?? null,
        ];
    }

    private function hasReferencePackTable(): bool
    {
        if ($this->referencePackTableExists !== null) {
            return $this->referencePackTableExists;
        }

        try {
            $this->referencePackTableExists = Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            $this->referencePackTableExists = false;
        }

        return $this->referencePackTableExists;
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
