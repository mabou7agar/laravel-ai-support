<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

use LaravelAIEngine\DTOs\AIRequest;

class FalCharacterGenerationService
{
    public function __construct(
        private FalReferencePackGenerationService $referencePackGenerationService
    ) {}

    public function prepareRequest(string $prompt, array $options = [], ?string $userId = null): AIRequest
    {
        return $this->referencePackGenerationService->prepareRequest(
            $prompt,
            $this->normalizeOptions($options),
            $userId
        );
    }

    public function prepareWorkflow(string $prompt, array $options = [], ?string $userId = null): array
    {
        return $this->referencePackGenerationService->prepareWorkflow(
            $prompt,
            $this->normalizeOptions($options),
            $userId
        );
    }

    public function generateAndStore(
        string $prompt,
        array $options = [],
        ?string $userId = null,
        ?callable $progress = null
    ): array {
        return $this->referencePackGenerationService->generateAndStore(
            $prompt,
            $this->normalizeOptions($options),
            $userId,
            $progress
        );
    }

    public function estimateCredits(array $options = [], ?string $userId = null): float
    {
        return $this->referencePackGenerationService->estimateCredits(
            $this->normalizeOptions($options),
            $userId
        );
    }

    private function normalizeOptions(array $options): array
    {
        $options['entity_type'] = 'character';

        if (!isset($options['from_reference_pack']) && isset($options['from_character'])) {
            $options['from_reference_pack'] = $options['from_character'];
        }

        return $options;
    }
}
