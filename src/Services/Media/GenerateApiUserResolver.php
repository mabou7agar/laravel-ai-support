<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Throwable;

class GenerateApiUserResolver
{
    public function id(): ?string
    {
        try {
            $id = auth()->id();

            return $id !== null ? (string) $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
