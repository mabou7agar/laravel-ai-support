<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;

class FalMediaWorkflowService
{
    public function __construct(
        private AIEngineService $aiEngineService,
        private FalCharacterStore $characterStore
    ) {}

    public function prepareRequest(string $prompt = '', array $options = [], ?string $userId = null): AIRequest
    {
        $model = $this->resolveModel($options);

        return new AIRequest(
            prompt: $prompt,
            engine: 'fal_ai',
            model: $model,
            parameters: $this->buildParameters($model, $options),
            userId: $this->resolveUserId($userId, $options)
        );
    }

    public function generate(string $prompt = '', array $options = [], ?string $userId = null): array
    {
        $request = $this->prepareRequest($prompt, $options, $userId);
        $response = $this->aiEngineService->generateDirect($request);

        return [
            'request' => $request,
            'response' => $response,
        ];
    }

    private function resolveModel(array $options): string
    {
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            return trim($options['model']);
        }

        $sourceImages = $this->normalizeStringArray($options['source_images'] ?? []);
        $referenceImageUrls = $this->normalizeStringArray($options['reference_image_urls'] ?? []);
        $rawCharacters = is_array($options['character_sources'] ?? null) ? $options['character_sources'] : [];
        $useCharacters = $this->normalizeStringArray($options['use_characters'] ?? []);
        $useLastCharacter = (bool) ($options['use_last_character'] ?? false);
        $mode = isset($options['mode']) ? (string) $options['mode'] : null;
        $startImageUrl = isset($options['start_image_url']) ? trim((string) $options['start_image_url']) : '';
        $endImageUrl = isset($options['end_image_url']) ? trim((string) $options['end_image_url']) : '';

        if ($mode === 'edit' || $sourceImages !== []) {
            return EntityEnum::FAL_NANO_BANANA_2_EDIT;
        }

        if ($referenceImageUrls !== [] || $rawCharacters !== [] || $useCharacters !== [] || $useLastCharacter) {
            return EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO;
        }

        if ($startImageUrl !== '' || $endImageUrl !== '') {
            return EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO;
        }

        return EntityEnum::FAL_NANO_BANANA_2;
    }

    private function buildParameters(string $model, array $options): array
    {
        $parameters = [];

        if (isset($options['frame_count'])) {
            $parameters['frame_count'] = max(1, (int) $options['frame_count']);
        }

        if (isset($options['duration']) && $options['duration'] !== '') {
            $parameters['duration'] = (string) $options['duration'];
        }

        foreach (['aspect_ratio', 'resolution', 'mode', 'thinking_level', 'output_format'] as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && trim($options[$key]) !== '') {
                $parameters[$key] = trim($options[$key]);
            }
        }

        if (isset($options['seed']) && $options['seed'] !== '') {
            $parameters['seed'] = (int) $options['seed'];
        }

        $startImageUrl = isset($options['start_image_url']) ? trim((string) $options['start_image_url']) : '';
        if ($startImageUrl !== '') {
            $parameters['start_image_url'] = $startImageUrl;
            $parameters['image_url'] = $startImageUrl;
        }

        $endImageUrl = isset($options['end_image_url']) ? trim((string) $options['end_image_url']) : '';
        if ($endImageUrl !== '') {
            $parameters['end_image_url'] = $endImageUrl;
        }

        $sourceImages = $this->normalizeStringArray($options['source_images'] ?? []);
        if ($sourceImages !== []) {
            $parameters['source_images'] = $sourceImages;
        }

        $referenceImageUrls = $this->normalizeStringArray($options['reference_image_urls'] ?? []);
        if ($referenceImageUrls !== []) {
            $parameters['reference_image_urls'] = $referenceImageUrls;
        }

        $characters = array_merge(
            $this->loadStoredCharacters($options),
            $this->normalizeCharacterSources($options['character_sources'] ?? [])
        );
        if ($characters !== []) {
            $parameters['character_sources'] = $characters;
        }

        $shots = $this->normalizeShots($options['multi_prompt'] ?? []);
        if ($shots !== []) {
            $parameters['multi_prompt'] = $shots;
        }

        if ((new EntityEnum($model))->contentType() === 'video') {
            $parameters['generate_audio'] = (bool) ($options['generate_audio'] ?? true);
        }

        $extraParameters = is_array($options['parameters'] ?? null) ? $options['parameters'] : [];
        foreach ($extraParameters as $key => $value) {
            if (is_string($key) && $key !== '') {
                $parameters[$key] = $value;
            }
        }

        return $parameters;
    }

    private function loadStoredCharacters(array $options): array
    {
        $characters = [];

        foreach ($this->normalizeStringArray($options['use_characters'] ?? []) as $alias) {
            $character = $this->characterStore->get($alias);
            if ($character === null) {
                throw new AIEngineException("Saved character [{$alias}] was not found.");
            }

            $characters[] = $character;
        }

        if ((bool) ($options['use_last_character'] ?? false)) {
            $character = $this->characterStore->getLast();
            if ($character === null) {
                throw new AIEngineException('No last generated character is available.');
            }

            $alreadyLoaded = array_filter($characters, static fn (array $loaded): bool => ($loaded['alias'] ?? null) === ($character['alias'] ?? null));
            if ($alreadyLoaded === []) {
                $characters[] = $character;
            }
        }

        return $characters;
    }

    private function normalizeCharacterSources(mixed $characterSources): array
    {
        if (!is_array($characterSources)) {
            return [];
        }

        $normalized = [];
        foreach ($characterSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $frontalImageUrl = isset($source['frontal_image_url']) ? trim((string) $source['frontal_image_url']) : '';
            $references = $this->normalizeStringArray($source['reference_image_urls'] ?? []);

            $normalized[] = array_filter([
                'name' => isset($source['name']) ? trim((string) $source['name']) : null,
                'description' => isset($source['description']) ? trim((string) $source['description']) : null,
                'frontal_image_url' => $frontalImageUrl !== '' ? $frontalImageUrl : null,
                'reference_image_urls' => $references,
                'metadata' => is_array($source['metadata'] ?? null) ? $source['metadata'] : [],
            ], static fn ($value): bool => $value !== null);
        }

        return array_values(array_filter($normalized));
    }

    private function normalizeShots(mixed $shots): array
    {
        if (!is_array($shots)) {
            return [];
        }

        $normalized = [];
        foreach ($shots as $shot) {
            if (!is_array($shot)) {
                continue;
            }

            $prompt = trim((string) ($shot['prompt'] ?? ''));
            if ($prompt === '') {
                throw new AIEngineException('Each multi_prompt entry must include a non-empty prompt.');
            }

            $item = ['prompt' => $prompt];
            if (isset($shot['duration']) && $shot['duration'] !== '') {
                $item['duration'] = (string) $shot['duration'];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    private function normalizeStringArray(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }, $values)));
    }

    private function resolveUserId(?string $userId, array $options = []): ?string
    {
        if (is_string($userId) && trim($userId) !== '') {
            return trim($userId);
        }

        if (($options['use_demo_user_id'] ?? false) !== true) {
            return null;
        }

        $configuredUserId = config('ai-engine.demo_user_id');

        return is_string($configuredUserId) && trim($configuredUserId) !== '' ? trim($configuredUserId) : null;
    }
}
