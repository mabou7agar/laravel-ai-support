<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts\Learning;

use LaravelAIEngine\DTOs\LearningSourcePayload;
use LaravelAIEngine\DTOs\LearningSourceRequest;

interface LearningSourceAdapterInterface
{
    public function supports(LearningSourceRequest $request): bool;

    public function fetch(LearningSourceRequest $request): LearningSourcePayload;
}
