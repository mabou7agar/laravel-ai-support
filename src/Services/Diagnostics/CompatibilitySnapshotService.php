<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Diagnostics;

use JsonException;
use LaravelAIEngine\DTOs\CompatibilitySurface;
use RuntimeException;

final class CompatibilitySnapshotService
{
    /** @return array<string, mixed> */
    public function snapshot(string $targetVersion = '3.0'): array
    {
        $targetVersion = trim($targetVersion);
        if ($targetVersion === '' || preg_match('/^[0-9A-Za-z._-]+$/', $targetVersion) !== 1) {
            throw new RuntimeException('Compatibility target versions may contain only letters, numbers, dots, underscores, and dashes.');
        }

        $path = dirname(__DIR__, 3).'/resources/compatibility/'.$targetVersion.'.json';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Compatibility snapshot [%s] was not found.', $targetVersion));
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                sprintf('Compatibility snapshot [%s] is invalid JSON.', $targetVersion),
                previous: $exception,
            );
        }

        if (!is_array($decoded) || (string) ($decoded['target_version'] ?? '') !== $targetVersion) {
            throw new RuntimeException(sprintf('Compatibility snapshot [%s] has an invalid target version.', $targetVersion));
        }

        $surfaces = array_map(
            static fn (mixed $surface): array => CompatibilitySurface::fromArray((array) $surface)->toArray(),
            (array) ($decoded['surfaces'] ?? []),
        );

        return [
            'schema_version' => (int) ($decoded['schema_version'] ?? 1),
            'target_version' => $targetVersion,
            'minimum_deprecation_release' => trim((string) ($decoded['minimum_deprecation_release'] ?? '')),
            'surfaces' => array_values($surfaces),
            'routes' => array_values((array) ($decoded['routes'] ?? [])),
            'public_classes' => array_values((array) ($decoded['public_classes'] ?? [])),
        ];
    }
}
