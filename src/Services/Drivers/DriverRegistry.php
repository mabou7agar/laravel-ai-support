<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Drivers;

use LaravelAIEngine\Contracts\EngineDriverInterface;
use LaravelAIEngine\Enums\EngineEnum;
use InvalidArgumentException;

class DriverRegistry
{
    /** @var array<string, callable> */
    protected array $drivers = [];

    /** @var array<string, EngineDriverInterface> */
    protected array $resolvedDrivers = [];

    /**
     * Register a driver factory for a given engine
     */
    public function register(string|EngineEnum $engine, callable $factory): void
    {
        $engineName = $engine instanceof EngineEnum ? $engine->value : $engine;
        $this->drivers[$engineName] = $factory;
        // clear resolved instance to allow overriding
        unset($this->resolvedDrivers[$engineName]);
    }

    /**
     * Resolve a driver instance
     */
    public function resolve(string|EngineEnum $engine): EngineDriverInterface
    {
        $engineName = $engine instanceof EngineEnum ? $engine->value : $engine;

        if (isset($this->resolvedDrivers[$engineName])) {
            return $this->resolvedDrivers[$engineName];
        }

        if (!isset($this->drivers[$engineName])) {
            // Try default resolution handling (legacy support)
            // If the enum has a driverClass() method, try to instantiate it
            if ($engine instanceof EngineEnum) {
                try {
                    $driverClass = $engine->driverClass();
                    if (class_exists($driverClass)) {
                        $config = config("ai-engine.engines.{$engineName}", []);
                        // Simple instantiation - complex DI should use register()
                        $instance = new $driverClass($config);
                        $this->resolvedDrivers[$engineName] = $instance;
                        return $instance;
                    }
                } catch (\Throwable $e) {
                    // fall through to exception
                }
            }

            throw new InvalidArgumentException("No driver registered for engine: {$engineName}");
        }

        $factory = $this->drivers[$engineName];
        $instance = $factory();

        if (!$instance instanceof EngineDriverInterface) {
            throw new InvalidArgumentException("Driver factory for {$engineName} must return EngineDriverInterface");
        }

        $this->resolvedDrivers[$engineName] = $instance;
        return $instance;
    }
}
