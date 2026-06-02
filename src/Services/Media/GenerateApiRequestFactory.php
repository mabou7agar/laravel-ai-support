<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Services\BrandVoiceManager;

class GenerateApiRequestFactory
{
    public function __construct(
        private readonly ?BrandVoiceManager $brandVoice = null
    ) {}

    public function text(array $validated, ?string $userId = null): AIRequest
    {
        $parameters = $this->arrayValue($validated, 'parameters');
        $prompt = $this->applyBrandVoice((string) $validated['prompt'], $parameters, $userId);

        return new AIRequest(
            prompt: $prompt,
            engine: array_key_exists('engine', $validated) ? (string) $validated['engine'] : null,
            model: array_key_exists('model', $validated) ? (string) $validated['model'] : null,
            parameters: $parameters,
            userId: $userId,
            systemPrompt: $validated['system_prompt'] ?? null,
            maxTokens: isset($validated['max_tokens']) ? (int) $validated['max_tokens'] : null,
            temperature: isset($validated['temperature']) ? (float) $validated['temperature'] : null,
            metadata: array_filter([
                'routing_preference' => $validated['preference'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    public function image(array $validated, string $engine, string $model, ?string $userId = null): AIRequest
    {
        $parameters = $this->arrayValue($validated, 'parameters');

        foreach (['size', 'quality', 'mode', 'aspect_ratio', 'resolution', 'thinking_level', 'output_format'] as $key) {
            if (!empty($validated[$key])) {
                $parameters[$key] = (string) $validated[$key];
            }
        }

        foreach (['frame_count', 'seed'] as $key) {
            if (isset($validated[$key])) {
                $parameters[$key] = (int) $validated[$key];
            }
        }

        foreach (['source_images', 'character_sources'] as $key) {
            if (!empty($validated[$key])) {
                $parameters[$key] = $validated[$key];
            }
        }

        return new AIRequest(
            prompt: (string) $validated['prompt'],
            engine: $engine,
            model: $model,
            parameters: array_merge($parameters, ['image_count' => (int) ($validated['count'] ?? 1)]),
            userId: $userId,
        );
    }

    public function transcription(array $validated, string $realPath, ?string $userId = null): AIRequest
    {
        $parameters = $this->arrayValue($validated, 'parameters');
        if (isset($validated['audio_minutes'])) {
            $parameters['audio_minutes'] = (float) $validated['audio_minutes'];
        }

        return new AIRequest(
            prompt: 'Transcribe this audio file.',
            engine: (string) ($validated['engine'] ?? 'openai'),
            model: (string) ($validated['model'] ?? 'whisper-1'),
            parameters: $parameters,
            files: [$realPath],
            userId: $userId,
        );
    }

    public function tts(array $validated, array $storedVoice = [], ?string $userId = null): AIRequest
    {
        $parameters = array_merge($storedVoice, $this->arrayValue($validated, 'parameters'));

        foreach (['voice_id'] as $key) {
            if (!empty($validated[$key])) {
                $parameters[$key] = (string) $validated[$key];
            }
        }

        foreach (['stability', 'similarity_boost', 'style'] as $key) {
            if (isset($validated[$key])) {
                $parameters[$key] = (float) $validated[$key];
            }
        }

        if (array_key_exists('use_speaker_boost', $validated)) {
            $parameters['use_speaker_boost'] = (bool) $validated['use_speaker_boost'];
        }

        return new AIRequest(
            prompt: (string) $validated['text'],
            engine: (string) ($validated['engine'] ?? 'eleven_labs'),
            model: (string) ($validated['model'] ?? 'eleven_multilingual_v2'),
            parameters: array_merge($parameters, ['audio_minutes' => (float) ($validated['minutes'] ?? 1.0)]),
            userId: $userId,
        );
    }

    private function arrayValue(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * Opt-in: augment the prompt with a user's stored brand voice when a
     * `brand_voice_id` selector is present in the request parameters and a
     * user id is known. When either is missing the prompt is returned
     * byte-for-byte unchanged.
     */
    private function applyBrandVoice(string $prompt, array $parameters, ?string $userId): string
    {
        $brandVoiceId = $parameters['brand_voice_id'] ?? null;

        if (empty($brandVoiceId) || $userId === null || $userId === '' || !$this->brandVoice instanceof BrandVoiceManager) {
            return $prompt;
        }

        return $this->brandVoice->applyBrandVoiceToPrompt($userId, (string) $brandVoiceId, $prompt);
    }
}
