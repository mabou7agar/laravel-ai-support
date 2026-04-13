<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\AIEngineException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;

class FalReferencePackGenerationService
{
    private const VIEW_PRESETS = [
        'character' => [
            ['key' => 'front', 'label' => 'Front portrait', 'instruction' => 'front-facing portrait view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter portrait view'],
            ['key' => 'side', 'label' => 'Side profile', 'instruction' => 'side profile view'],
            ['key' => 'full_body', 'label' => 'Full body', 'instruction' => 'full-body reference shot'],
        ],
        'object' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front product view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter product view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side product view'],
            ['key' => 'full', 'label' => 'Full shot', 'instruction' => 'full isolated product shot'],
        ],
        'furniture' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front furniture view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter furniture view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side furniture view'],
            ['key' => 'full', 'label' => 'Full shot', 'instruction' => 'full isolated furniture shot'],
        ],
        'vehicle' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front vehicle view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter vehicle view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side vehicle view'],
            ['key' => 'rear_three_quarter', 'label' => 'Rear three-quarter', 'instruction' => 'rear three-quarter vehicle view'],
        ],
        'product' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front product view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter product view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side product view'],
            ['key' => 'detail', 'label' => 'Detail shot', 'instruction' => 'clean detail-focused product shot'],
        ],
        'prop' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front prop view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter prop view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side prop view'],
            ['key' => 'full', 'label' => 'Full shot', 'instruction' => 'full isolated prop shot'],
        ],
        'creature' => [
            ['key' => 'front', 'label' => 'Front view', 'instruction' => 'front creature view'],
            ['key' => 'three_quarter', 'label' => 'Three-quarter view', 'instruction' => 'three-quarter creature view'],
            ['key' => 'side', 'label' => 'Side view', 'instruction' => 'side creature view'],
            ['key' => 'full_body', 'label' => 'Full body', 'instruction' => 'full-body creature reference shot'],
        ],
    ];

    private const LOOK_PRESETS = [
        'character' => [
            ['key' => 'signature', 'label' => 'Signature look', 'instruction' => 'Keep the same hairstyle, makeup, clothing, and accessories exactly the same as the base design.'],
            ['key' => 'beauty_variant', 'label' => 'Hair and makeup variant', 'instruction' => 'Create a fresh variant by changing hair styling and makeup while preserving the same face, identity, and overall silhouette.'],
            ['key' => 'fashion_variant', 'label' => 'Styled variant', 'instruction' => 'Create another styling variant with updated hair, makeup, and fashion styling while preserving the same identity.'],
            ['key' => 'cinematic_variant', 'label' => 'Cinematic variant', 'instruction' => 'Create a cinematic styling variant with a new beauty direction and polished wardrobe styling while preserving the same identity.'],
        ],
        'default' => [
            ['key' => 'signature', 'label' => 'Primary look', 'instruction' => 'Keep the exact same form, materials, silhouette, and proportions as the base design.'],
            ['key' => 'material_variant', 'label' => 'Material and color variant', 'instruction' => 'Create a new variant by changing material, finish, and color while preserving the same identity and silhouette.'],
            ['key' => 'styled_variant', 'label' => 'Styled variant', 'instruction' => 'Create another distinct design variant while preserving the same recognizable identity and proportions.'],
            ['key' => 'premium_variant', 'label' => 'Premium variant', 'instruction' => 'Create a premium design variation while preserving the same core identity and silhouette.'],
        ],
    ];

    public function __construct(
        private AIEngineService $aiEngineService,
        private FalCharacterStore $referencePackStore
    ) {}

    public function prepareRequest(string $prompt, array $options = [], ?string $userId = null): AIRequest
    {
        $workflow = $this->prepareWorkflow($prompt, $options, $userId);
        $firstStep = $workflow[0] ?? null;

        if (!is_array($firstStep)) {
            throw new AIEngineException('Unable to prepare reference pack workflow.');
        }

        return new AIRequest(
            prompt: (string) $firstStep['prompt'],
            engine: 'fal_ai',
            model: (string) $firstStep['model'],
            parameters: is_array($firstStep['parameters'] ?? null) ? $firstStep['parameters'] : [],
            userId: $this->resolveUserId($userId, $options)
        );
    }

    public function prepareWorkflow(string $prompt, array $options = [], ?string $userId = null): array
    {
        $resolvedUserId = $this->resolveUserId($userId, $options);
        $basePack = $this->resolveBaseReferencePack($options);
        $workflow = [];

        foreach ($this->buildGenerationPlan($options) as $stepIndex => $step) {
            $isInitialStep = $basePack === null && $stepIndex === 0;

            $workflow[] = [
                'step' => $stepIndex + 1,
                'look_index' => $step['look_index'],
                'look_label' => $step['look_label'],
                'look_variant' => $step['look_variant'],
                'view_index' => $step['view_index'],
                'view' => $step['view'],
                'label' => $step['label'],
                'entity_type' => $step['entity_type'],
                'model' => $isInitialStep ? EntityEnum::FAL_NANO_BANANA_2 : EntityEnum::FAL_NANO_BANANA_2_EDIT,
                'prompt' => $this->buildPrompt($prompt, $step, $isInitialStep),
                'parameters' => $this->buildStepParameters($options, $basePack !== null ? [$this->buildExistingImageRecord($basePack, $step['entity_type'])] : [], !$isInitialStep),
                'user_id' => $resolvedUserId,
            ];
        }

        return $workflow;
    }

    public function generateAndStore(
        string $prompt,
        array $options = [],
        ?string $userId = null,
        ?callable $progress = null
    ): array {
        $resolvedUserId = $this->resolveUserId($userId, $options);
        $entityType = $this->resolveEntityType($options);
        $basePack = $this->resolveBaseReferencePack($options);
        $plan = $this->buildGenerationPlan($options);
        $generatedImages = $basePack !== null ? [$this->buildExistingImageRecord($basePack, $entityType)] : [];
        $generatedByLook = $basePack !== null ? [1 => [$this->buildExistingImageRecord($basePack, $entityType)]] : [];
        $identityAnchorImages = $basePack !== null ? [$this->buildExistingImageRecord($basePack, $entityType)] : [];
        $requests = [];
        $responses = [];

        foreach ($plan as $stepIndex => $step) {
            $referenceImages = $this->resolveReferenceImages($step, $generatedByLook, $identityAnchorImages);
            $request = $this->buildStepRequest($prompt, $options, $resolvedUserId, $step, $referenceImages, $basePack === null && $stepIndex === 0);
            $requests[] = $request;

            if (is_callable($progress)) {
                $progress([
                    'step' => $stepIndex + 1,
                    'total_steps' => count($plan),
                    'look_index' => $step['look_index'],
                    'look_label' => $step['look_label'],
                    'view_index' => $step['view_index'],
                    'view' => $step['view'],
                    'label' => $step['label'],
                    'request' => $request,
                ]);
            }

            $response = $this->aiEngineService->generateDirect($request);
            if (!$response->isSuccess()) {
                throw new AIEngineException($response->getError() ?? 'Reference pack generation failed.');
            }

            $responses[] = $response;
            $image = $this->extractGeneratedImage($response, $step, $stepIndex + 1);
            $generatedImages[] = $image;
            $generatedByLook[$step['look_index']][] = $image;

            if ($step['look_index'] === 1) {
                $identityAnchorImages[] = $image;
            }
        }

        $referencePack = $this->buildReferencePackFromImages($generatedImages, $options);
        $alias = $this->referencePackStore->save($referencePack, isset($options['save_as']) ? (string) $options['save_as'] : null);

        return [
            'alias' => $alias,
            'reference_pack' => $this->referencePackStore->get($alias) ?? array_merge($referencePack, ['alias' => $alias]),
            'character' => $this->referencePackStore->get($alias) ?? array_merge($referencePack, ['alias' => $alias]),
            'response' => $this->buildWorkflowResponse($responses, $generatedImages, $referencePack, $options),
            'request' => $requests[0] ?? null,
            'requests' => $requests,
            'responses' => $responses,
            'workflow' => $this->prepareWorkflow($prompt, $options, $resolvedUserId),
        ];
    }

    public function estimateCredits(array $options = [], ?string $userId = null): float
    {
        $total = 0.0;

        foreach ($this->prepareWorkflow('Estimate reference workflow', $options, $userId) as $step) {
            $request = new AIRequest(
                prompt: (string) $step['prompt'],
                engine: 'fal_ai',
                model: (string) $step['model'],
                parameters: is_array($step['parameters'] ?? null) ? $step['parameters'] : [],
                userId: $userId
            );

            $total += $request->getModel()->creditIndex();
        }

        return $total;
    }

    private function buildStepRequest(
        string $prompt,
        array $options,
        ?string $userId,
        array $step,
        array $referenceImages,
        bool $isInitialStep
    ): AIRequest {
        return new AIRequest(
            prompt: $this->buildPrompt($prompt, $step, $isInitialStep),
            engine: 'fal_ai',
            model: $isInitialStep ? EntityEnum::FAL_NANO_BANANA_2 : EntityEnum::FAL_NANO_BANANA_2_EDIT,
            parameters: $this->buildStepParameters($options, $referenceImages, !$isInitialStep),
            userId: $userId
        );
    }

    private function buildGenerationPlan(array $options): array
    {
        $requestedCount = max(1, (int) ($options['frame_count'] ?? 3));
        $lookSize = max(1, (int) ($options['look_size'] ?? min(4, $requestedCount)));
        $entityType = $this->resolveEntityType($options);
        $viewPresets = self::VIEW_PRESETS[$entityType] ?? self::VIEW_PRESETS['object'];
        $lookPresets = self::LOOK_PRESETS[$entityType] ?? self::LOOK_PRESETS['default'];
        $plan = [];

        for ($index = 0; $index < $requestedCount; $index++) {
            $lookIndex = intdiv($index, $lookSize) + 1;
            $viewIndex = ($index % $lookSize) + 1;
            $view = $viewPresets[$viewIndex - 1] ?? [
                'key' => 'view_' . $viewIndex,
                'label' => 'Reference ' . $viewIndex,
                'instruction' => 'alternate reference angle ' . $viewIndex,
            ];
            $look = $lookPresets[$lookIndex - 1] ?? [
                'key' => 'variant_' . $lookIndex,
                'label' => 'Variant ' . $lookIndex,
                'instruction' => 'Create another distinct variant while preserving the same identity and proportions.',
            ];

            $plan[] = [
                'entity_type' => $entityType,
                'look_index' => $lookIndex,
                'look_variant' => $look['key'],
                'look_label' => $look['label'],
                'look_instruction' => $look['instruction'],
                'view_index' => $viewIndex,
                'view' => $view['key'],
                'view_label' => $view['label'],
                'view_instruction' => $view['instruction'],
                'label' => sprintf('Look %d: %s / %s', $lookIndex, $look['label'], $view['label']),
            ];
        }

        if (($options['preview_only'] ?? false) === true) {
            return array_slice($plan, 0, 1);
        }

        if ($this->resolveBaseReferencePack($options) !== null) {
            if ($requestedCount < 2) {
                throw new AIEngineException('frame_count must be at least 2 when expanding from an approved preview.');
            }

            return array_slice($plan, 1);
        }

        return $plan;
    }

    private function buildPrompt(string $prompt, array $step, bool $isInitialStep): string
    {
        $entityLabel = str_replace('_', ' ', $step['entity_type']);
        $segments = [
            trim($prompt),
            'Build a reusable ' . $entityLabel . ' reference pack image.',
            'Look: ' . $step['look_label'] . '.',
            $step['look_instruction'],
            'View: ' . $step['view_instruction'] . '.',
        ];

        if ($step['entity_type'] === 'character') {
            $segments[] = $isInitialStep
                ? 'Establish the core identity with a clean neutral background and clearly readable face, hair, clothing, and body proportions.'
                : 'Use the provided references to preserve the exact same identity while applying the requested view or style change.';
            $segments[] = 'Do not change the person into someone else.';
        } else {
            $segments[] = $isInitialStep
                ? 'Establish the base design with a clean neutral background and clearly readable silhouette, materials, and proportions.'
                : 'Use the provided references to preserve the exact same object identity, proportions, and silhouette while applying the requested view or variant.';
            $segments[] = 'Do not turn it into a different design.';
        }

        return trim(implode(' ', array_filter($segments)));
    }

    private function buildStepParameters(array $options, array $referenceImages, bool $editMode): array
    {
        $parameters = [
            'frame_count' => 1,
            'image_count' => 1,
        ];

        foreach (['aspect_ratio', 'resolution', 'thinking_level', 'output_format'] as $key) {
            if (isset($options[$key]) && is_string($options[$key]) && trim($options[$key]) !== '') {
                $parameters[$key] = trim($options[$key]);
            }
        }

        if (isset($options['seed']) && $options['seed'] !== '') {
            $parameters['seed'] = (int) $options['seed'];
        }

        if ($editMode) {
            $parameters['mode'] = 'edit';
        }

        if ($editMode && $referenceImages !== []) {
            $referenceUrls = array_values(array_unique(array_filter(array_map(
                static fn (array $image): ?string => isset($image['url']) && is_string($image['url']) ? $image['url'] : null,
                $referenceImages
            ))));

            if ($referenceUrls !== []) {
                $parameters['source_images'] = $referenceUrls;
                $parameters['character_sources'] = [[
                    'name' => $this->resolveDisplayName($options),
                    'description' => 'Reference pack generated by ai-engine:generate-reference-pack',
                    'frontal_image_url' => $referenceUrls[0] ?? null,
                    'reference_image_urls' => array_slice($referenceUrls, 1),
                    'metadata' => [
                        'workflow' => 'reference_pack',
                        'entity_type' => $this->resolveEntityType($options),
                    ],
                ]];
            }
        }

        return $parameters;
    }

    private function resolveReferenceImages(array $step, array $generatedByLook, array $identityAnchorImages): array
    {
        if ($step['look_index'] === 1) {
            return $generatedByLook[1] ?? [];
        }

        $currentLookImages = $generatedByLook[$step['look_index']] ?? [];

        if ($step['view_index'] === 1) {
            return $identityAnchorImages;
        }

        return array_values(array_merge($identityAnchorImages, $currentLookImages));
    }

    private function extractGeneratedImage(AIResponse $response, array $step, int $stepNumber): array
    {
        $images = $response->getMetadata()['images'] ?? [];
        if (!is_array($images) || $images === []) {
            throw new AIEngineException('No images were returned for reference pack step ' . $stepNumber . '.');
        }

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }

            $url = $image['url'] ?? $image['source_url'] ?? null;
            if (!is_string($url) || trim($url) === '') {
                continue;
            }

            return [
                'url' => trim($url),
                'source_url' => is_string($image['source_url'] ?? null) ? $image['source_url'] : $url,
                'entity_type' => $step['entity_type'],
                'look_index' => $step['look_index'],
                'look_variant' => $step['look_variant'],
                'look_label' => $step['look_label'],
                'view_index' => $step['view_index'],
                'view' => $step['view'],
                'view_label' => $step['view_label'],
                'label' => $step['label'],
                'step' => $stepNumber,
            ];
        }

        throw new AIEngineException('Returned reference pack images did not include usable URLs for step ' . $stepNumber . '.');
    }

    private function buildReferencePackFromImages(array $generatedImages, array $options): array
    {
        if ($generatedImages === []) {
            throw new AIEngineException('No images were returned to save as a reference pack.');
        }

        $urls = array_values(array_filter(array_map(
            static fn (array $image): ?string => isset($image['url']) && is_string($image['url']) ? $image['url'] : null,
            $generatedImages
        )));

        if ($urls === []) {
            throw new AIEngineException('Returned reference pack images did not include provider URLs.');
        }

        $looks = [];
        foreach ($generatedImages as $image) {
            $lookIndex = (int) $image['look_index'];

            if (!isset($looks[$lookIndex])) {
                $looks[$lookIndex] = [
                    'look_index' => $lookIndex,
                    'variant' => $image['look_variant'],
                    'label' => $image['look_label'],
                    'frontal_image_url' => $image['url'],
                    'reference_image_urls' => [],
                    'views' => [],
                ];
            }

            $looks[$lookIndex]['views'][] = [
                'view' => $image['view'],
                'label' => $image['view_label'],
                'url' => $image['url'],
            ];

            if ($image['view_index'] === 1) {
                $looks[$lookIndex]['frontal_image_url'] = $image['url'];
            } else {
                $looks[$lookIndex]['reference_image_urls'][] = $image['url'];
            }
        }

        ksort($looks);

        $voicePayload = $this->buildVoicePayload($options);

        return [
            'name' => $this->resolveDisplayName($options),
            'description' => 'Generated by ai-engine:generate-reference-pack',
            'frontal_image_url' => $urls[0],
            'reference_image_urls' => array_slice($urls, 1),
            'voice_id' => $voicePayload['voice_id'] ?? null,
            'voice_settings' => $voicePayload['voice_settings'] ?? [],
            'metadata' => [
                'source' => 'ai-engine:generate-reference-pack',
                'entity_type' => $this->resolveEntityType($options),
                'generated_images' => $urls,
                'workflow' => 'reference_pack',
                'preview_only' => ($options['preview_only'] ?? false) === true,
                'expanded_from_character' => $options['from_character'] ?? $options['from_reference_pack'] ?? null,
                'requested_frame_count' => count($generatedImages),
                'look_size' => max(1, (int) ($options['look_size'] ?? min(4, count($generatedImages)))),
                'looks' => array_values($looks),
                'views' => array_map(
                    static fn (array $image): array => [
                        'entity_type' => $image['entity_type'],
                        'look_index' => $image['look_index'],
                        'look_variant' => $image['look_variant'],
                        'look_label' => $image['look_label'],
                        'view' => $image['view'],
                        'label' => $image['view_label'],
                        'url' => $image['url'],
                    ],
                    $generatedImages
                ),
                'resolved_model' => EntityEnum::FAL_NANO_BANANA_2,
                'voice' => $voicePayload,
            ],
        ];
    }

    private function buildVoicePayload(array $options): array
    {
        $payload = [];

        if (isset($options['voice_id']) && is_string($options['voice_id']) && trim($options['voice_id']) !== '') {
            $payload['voice_id'] = trim($options['voice_id']);
        }

        $voiceSettings = [];
        $rawVoiceSettings = is_array($options['voice_settings'] ?? null) ? $options['voice_settings'] : [];
        foreach (['stability', 'similarity_boost', 'style', 'use_speaker_boost'] as $key) {
            if (array_key_exists($key, $rawVoiceSettings)) {
                $voiceSettings[$key] = $rawVoiceSettings[$key];
            }
        }

        if ($voiceSettings !== []) {
            $payload['voice_settings'] = $voiceSettings;
        }

        return $payload;
    }

    private function buildWorkflowResponse(array $responses, array $generatedImages, array $referencePack, array $options): AIResponse
    {
        $files = array_values(array_filter(array_map(
            static fn (array $image): ?string => isset($image['url']) && is_string($image['url']) ? $image['url'] : null,
            $generatedImages
        )));

        $totalCredits = array_reduce(
            $responses,
            static fn (float $carry, AIResponse $response): float => $carry + $response->getCreditsUsed(),
            0.0
        );

        return AIResponse::success(
            json_encode($generatedImages, JSON_UNESCAPED_SLASHES),
            'fal_ai',
            EntityEnum::FAL_NANO_BANANA_2
        )->withFiles($files)
            ->withUsage(creditsUsed: $totalCredits)
            ->withMetadata([
                'workflow' => 'reference_pack',
                'entity_type' => $this->resolveEntityType($options),
                'images' => array_map(
                    static fn (array $image): array => [
                        'url' => $image['url'],
                        'source_url' => $image['source_url'] ?? $image['url'],
                        'entity_type' => $image['entity_type'],
                        'look_index' => $image['look_index'],
                        'look_variant' => $image['look_variant'],
                        'look_label' => $image['look_label'],
                        'view_index' => $image['view_index'],
                        'view' => $image['view'],
                        'label' => $image['view_label'],
                        'step' => $image['step'],
                    ],
                    $generatedImages
                ),
                'looks' => $referencePack['metadata']['looks'] ?? [],
                'preview_only' => ($options['preview_only'] ?? false) === true,
                'expanded_from_character' => $options['from_character'] ?? $options['from_reference_pack'] ?? null,
                'requested_frame_count' => max(1, (int) ($options['frame_count'] ?? 3)),
                'look_size' => max(1, (int) ($options['look_size'] ?? min(4, max(1, (int) ($options['frame_count'] ?? 3))))),
            ]);
    }

    private function resolveEntityType(array $options): string
    {
        $entityType = trim((string) ($options['entity_type'] ?? 'character'));

        return $entityType !== '' ? $entityType : 'character';
    }

    private function resolveDisplayName(array $options): string
    {
        return trim((string) ($options['name'] ?? $options['save_as'] ?? 'Generated Reference Pack'));
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

    private function resolveBaseReferencePack(array $options): ?array
    {
        $alias = trim((string) ($options['from_reference_pack'] ?? $options['from_character'] ?? ''));
        if ($alias === '') {
            return null;
        }

        $referencePack = $this->referencePackStore->get($alias);
        if ($referencePack === null) {
            throw new AIEngineException("Saved reference pack [{$alias}] was not found.");
        }

        if (!is_string($referencePack['frontal_image_url'] ?? null) || trim((string) $referencePack['frontal_image_url']) === '') {
            throw new AIEngineException("Saved reference pack [{$alias}] does not have a usable preview image.");
        }

        return $referencePack;
    }

    private function buildExistingImageRecord(array $referencePack, string $entityType): array
    {
        return [
            'url' => (string) $referencePack['frontal_image_url'],
            'source_url' => (string) $referencePack['frontal_image_url'],
            'entity_type' => $entityType,
            'look_index' => 1,
            'look_variant' => 'signature',
            'look_label' => $entityType === 'character' ? 'Signature look' : 'Primary look',
            'view_index' => 1,
            'view' => 'front',
            'view_label' => $entityType === 'character' ? 'Front portrait' : 'Front view',
            'label' => 'Look 1 / Front',
            'step' => 0,
        ];
    }
}
