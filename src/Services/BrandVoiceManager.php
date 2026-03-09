<?php

namespace LaravelAIEngine\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BrandVoiceManager
{
    public function createBrandVoice(string|int $userId, array $brandVoiceData): array
    {
        $voice = array_merge($brandVoiceData, [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ]);

        $voices = $this->getUserBrandVoices($userId);
        $voices[] = $voice;
        $this->storeVoices($userId, $voices);

        return $voice;
    }

    public function getBrandVoice(string|int $userId, string $brandVoiceId): ?array
    {
        foreach ($this->getUserBrandVoices($userId) as $voice) {
            if (($voice['id'] ?? null) === $brandVoiceId) {
                return $voice;
            }
        }

        return null;
    }

    public function updateBrandVoice(string|int $userId, string $brandVoiceId, array $updateData): bool
    {
        $voices = $this->getUserBrandVoices($userId);

        foreach ($voices as $index => $voice) {
            if (($voice['id'] ?? null) !== $brandVoiceId) {
                continue;
            }

            $voices[$index] = array_merge($voice, $updateData, [
                'id' => $voice['id'],
                'user_id' => $voice['user_id'],
                'created_at' => $voice['created_at'] ?? now()->toISOString(),
                'updated_at' => now()->toISOString(),
            ]);

            $this->storeVoices($userId, $voices);

            return true;
        }

        return false;
    }

    public function deleteBrandVoice(string|int $userId, string $brandVoiceId): bool
    {
        $voices = $this->getUserBrandVoices($userId);
        $filtered = array_values(array_filter(
            $voices,
            fn (array $voice): bool => ($voice['id'] ?? null) !== $brandVoiceId
        ));

        if (count($filtered) === count($voices)) {
            return false;
        }

        $this->storeVoices($userId, $filtered);

        return true;
    }

    public function getUserBrandVoices(string|int $userId): array
    {
        $path = $this->pathFor($userId);
        if (!Storage::disk('local')->exists($path)) {
            return [];
        }

        $data = json_decode((string) Storage::disk('local')->get($path), true);

        return is_array($data) ? $data : [];
    }

    public function applyBrandVoiceToPrompt(string|int $userId, string $brandVoiceId, string $prompt): string
    {
        $voice = $this->getBrandVoice($userId, $brandVoiceId);
        if (!$voice) {
            return $prompt;
        }

        $instructions = [];
        if (!empty($voice['tone'])) {
            $instructions[] = "Use a {$voice['tone']} tone.";
        }
        if (!empty($voice['style'])) {
            $instructions[] = "Writing style should be {$voice['style']}.";
        }
        if (!empty($voice['target_audience'])) {
            $instructions[] = "Target audience: {$voice['target_audience']}.";
        }
        if (!empty($voice['key_messages']) && is_array($voice['key_messages'])) {
            $instructions[] = 'Emphasize: ' . implode(', ', $voice['key_messages']) . '.';
        }
        if (!empty($voice['avoid_words']) && is_array($voice['avoid_words'])) {
            $instructions[] = 'Avoid words: ' . implode(', ', $voice['avoid_words']) . '.';
        }

        if ($instructions === []) {
            return $prompt;
        }

        return trim($prompt . "\n\nBrand voice instructions:\n- " . implode("\n- ", $instructions));
    }

    public function analyzeContent(string|int $userId, string $brandVoiceId, string $content): array
    {
        $voice = $this->getBrandVoice($userId, $brandVoiceId);
        if (!$voice) {
            return [
                'score' => 0,
                'issues' => ['Brand voice not found.'],
                'suggestions' => ['Select a valid brand voice first.'],
            ];
        }

        $issues = [];
        $suggestions = [];
        $score = 100;

        $avoidWords = is_array($voice['avoid_words'] ?? null) ? $voice['avoid_words'] : [];
        foreach ($avoidWords as $word) {
            $word = trim((string) $word);
            if ($word === '') {
                continue;
            }

            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $content) === 1) {
                $issues[] = "Avoided word found: {$word}";
                $suggestions[] = "Replace '{$word}' with a brand-approved alternative.";
                $score -= 15;
            }
        }

        $score = max(0, $score);

        if ($issues === []) {
            $suggestions[] = 'Content aligns well with the selected brand voice.';
        }

        return [
            'score' => $score,
            'issues' => $issues,
            'suggestions' => $suggestions,
        ];
    }

    public function validateBrandVoiceData(array $data): bool
    {
        $name = trim((string) ($data['name'] ?? ''));

        return $name !== '';
    }

    public function getBrandVoiceSuggestions(string $industry): array
    {
        $industry = strtolower(trim($industry));

        $suggestions = [
            [
                'name' => 'Professional Authority',
                'tone' => 'professional',
                'style' => 'informative',
                'description' => 'Clear, trusted, and precise communication.',
            ],
            [
                'name' => 'Friendly Expert',
                'tone' => 'friendly',
                'style' => 'conversational',
                'description' => 'Human and approachable while staying accurate.',
            ],
            [
                'name' => 'Bold Innovator',
                'tone' => 'confident',
                'style' => 'persuasive',
                'description' => 'Future-focused messaging with decisive language.',
            ],
        ];

        if ($industry === 'technology') {
            $suggestions[] = [
                'name' => 'Technical Builder',
                'tone' => 'professional',
                'style' => 'practical',
                'description' => 'Developer-friendly voice focused on real implementation value.',
            ];
        }

        return $suggestions;
    }

    public function exportBrandVoice(string|int $userId, string $brandVoiceId): ?array
    {
        $voice = $this->getBrandVoice($userId, $brandVoiceId);
        if (!$voice) {
            return null;
        }

        return array_merge($voice, [
            'export_version' => '1.0',
            'exported_at' => now()->toISOString(),
        ]);
    }

    public function importBrandVoice(string|int $userId, array $importData): array
    {
        unset($importData['id'], $importData['user_id'], $importData['created_at'], $importData['updated_at']);

        return $this->createBrandVoice($userId, $importData);
    }

    protected function storeVoices(string|int $userId, array $voices): void
    {
        Storage::disk('local')->put($this->pathFor($userId), json_encode(array_values($voices), JSON_PRETTY_PRINT));
    }

    protected function pathFor(string|int $userId): string
    {
        return 'ai-engine/brand-voices/' . (string) $userId . '.json';
    }
}

