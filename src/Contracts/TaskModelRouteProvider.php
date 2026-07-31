<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use LaravelAIEngine\DTOs\TaskModelRoute;

interface TaskModelRouteProvider
{
    /** @return iterable<TaskModelRoute|array<string, mixed>> */
    public function routes(): iterable;
}
