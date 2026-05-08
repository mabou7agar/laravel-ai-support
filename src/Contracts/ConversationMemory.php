<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

use DateInterval;
use DateTimeInterface;

interface ConversationMemory
{
    public function get(string $namespace, string $key, mixed $default = null): mixed;

    public function put(string $namespace, string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): void;

    public function forget(string $namespace, string $key): void;

    public function pull(string $namespace, string $key, mixed $default = null): mixed;
}

