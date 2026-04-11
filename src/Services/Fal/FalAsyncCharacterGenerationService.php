<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Fal;

class FalAsyncCharacterGenerationService
{
    public function __construct(
        private FalAsyncReferencePackGenerationService $asyncReferencePackGenerationService
    ) {}

    public function submit(string $prompt, array $options = [], ?string $userId = null): array
    {
        return $this->asyncReferencePackGenerationService->submit(
            $prompt,
            $this->normalizeOptions($options),
            $userId
        );
    }

    public function getStatus(string $jobId): ?array
    {
        return $this->asyncReferencePackGenerationService->getStatus($jobId);
    }

    public function waitForCompletion(string $jobId, int $timeoutSeconds = 900, int $pollIntervalSeconds = 5): array
    {
        return $this->asyncReferencePackGenerationService->waitForCompletion(
            $jobId,
            $timeoutSeconds,
            $pollIntervalSeconds
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
