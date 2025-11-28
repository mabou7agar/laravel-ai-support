<?php

namespace LaravelAIEngine\Services\Vector;

use LaravelAIEngine\Services\Vector\Contracts\VectorDriverInterface;
use LaravelAIEngine\Services\Vector\Drivers\QdrantDriver;
use LaravelAIEngine\Services\Vector\Drivers\PineconeDriver;
use InvalidArgumentException;

class VectorDriverManager
{
    protected array $drivers = [];
    protected ?string $defaultDriver = null;

    public function __construct()
    {
        $this->defaultDriver = config('ai-engine.vector.default_driver', 'qdrant');
    }

    /**
     * Get driver instance
     */
    public function driver(?string $name = null): VectorDriverInterface
    {
        $name = $name ?? $this->defaultDriver;

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Create driver instance
     */
    protected function createDriver(string $name): VectorDriverInterface
    {
        $config = config("ai-engine.vector.drivers.{$name}");

        if (!$config) {
            throw new InvalidArgumentException("Vector driver [{$name}] is not configured.");
        }

        return match ($name) {
            'qdrant' => new QdrantDriver($config),
            'pinecone' => new PineconeDriver($config),
            default => throw new InvalidArgumentException("Vector driver [{$name}] is not supported."),
        };
    }

    /**
     * Register custom driver
     */
    public function extend(string $name, callable $callback): void
    {
        $this->drivers[$name] = $callback();
    }

    /**
     * Get default driver name
     */
    public function getDefaultDriver(): string
    {
        return $this->defaultDriver;
    }

    /**
     * Set default driver
     */
    public function setDefaultDriver(string $name): void
    {
        $this->defaultDriver = $name;
    }
}
