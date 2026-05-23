<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Learning\Adapters;

use LaravelAIEngine\Contracts\Learning\LearningSourceAdapterInterface;
use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;

class TextLearningAdapter implements LearningSourceAdapterInterface
{
    public function supports(LearningSourceRequest $request): bool
    {
        return $request->sourceType === 'text';
    }

    public function fetch(LearningSourceRequest $request): LearningSourcePayload
    {
        return new LearningSourcePayload(
            content: $request->source,
            title: $request->title,
            metadata: [
                'source_type' => 'text',
            ],
        );
    }
}
