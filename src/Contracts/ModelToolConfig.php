<?php

declare(strict_types=1);

namespace LaravelAIEngine\Contracts;

/**
 * Base class for model-specific tool metadata.
 *
 * Host apps can expose model tools, filter metadata, validation, and transforms
 * without coupling the package to a project-specific workflow.
 */
abstract class ModelToolConfig
{
    abstract public static function getModelClass(): string;

    abstract public static function getName(): string;

    abstract public static function getDescription(): string;

    /**
     * @return array<string, mixed>
     */
    public static function getFilterConfig(): array
    {
        return [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getTools(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function getAllowedOperations(mixed $userId = null): array
    {
        if ($userId === null || $userId === '') {
            return ['list'];
        }

        return ['list', 'create', 'update', 'delete'];
    }

    /**
     * @return array{valid: bool, errors: array}
     */
    public static function validate(array $data, string $operation = 'create'): array
    {
        return ['valid' => true, 'errors' => []];
    }

    /**
     * @return array<string, mixed>
     */
    public static function transformData(array $data, string $operation = 'create'): array
    {
        return $data;
    }
}
