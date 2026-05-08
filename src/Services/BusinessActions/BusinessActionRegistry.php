<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\BusinessActions;

use InvalidArgumentException;
use LaravelAIEngine\Contracts\BusinessActionDefinitionProvider;

class BusinessActionRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    protected array $actions = [];

    /**
     * @param array<string, mixed> $definition
     */
    public function register(array $definition): void
    {
        $id = trim((string) ($definition['id'] ?? ''));

        if ($id === '') {
            throw new InvalidArgumentException('Business action definition must include a non-empty id.');
        }

        $this->actions[$id] = array_merge([
            'enabled' => true,
            'operation' => 'custom',
            'parameters' => [],
            'required' => [],
            'confirmation_required' => true,
        ], $definition, ['id' => $id]);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     */
    public function registerBatch(array $definitions): void
    {
        foreach ($definitions as $id => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            if (!isset($definition['id']) && is_string($id)) {
                $definition['id'] = $id;
            }

            $this->register($definition);
        }
    }

    /**
     * @param iterable<int|string, BusinessActionDefinitionProvider|string> $providers
     */
    public function registerProviders(iterable $providers): void
    {
        foreach ($providers as $provider) {
            if (is_string($provider) && class_exists($provider)) {
                $provider = app($provider);
            }

            if (!$provider instanceof BusinessActionDefinitionProvider) {
                throw new InvalidArgumentException(sprintf(
                    'Business action provider must implement %s.',
                    BusinessActionDefinitionProvider::class
                ));
            }

            foreach ($provider->actions() as $id => $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                if (!isset($definition['id']) && is_string($id)) {
                    $definition['id'] = $id;
                }

                $this->register($definition);
            }
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(bool $enabledOnly = true): array
    {
        if (!$enabledOnly) {
            return $this->actions;
        }

        return array_filter($this->actions, static fn (array $action): bool => (bool) ($action['enabled'] ?? true));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        return $this->actions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return isset($this->actions[$id]);
    }
}
