<?php

declare(strict_types=1);

namespace LaravelAIEngine\Exceptions;

/**
 * Thrown when a driver requires an optional SDK/dependency that is not installed.
 *
 * The package keeps heavyweight provider SDKs (e.g. aws/aws-sdk-php) out of the
 * hard "require" list and only suggests them. Drivers guard the SDK behind a
 * class_exists() check and throw this exception with an actionable message when
 * the dependency is missing.
 */
class MissingDependencyException extends AIEngineException
{
    /**
     * Build an exception telling the user which composer package to install.
     */
    public static function forPackage(string $package, string $context = ''): self
    {
        $suffix = $context !== '' ? " {$context}" : '';

        return new self(
            "The \"{$package}\" package is required{$suffix}. Install it with: composer require {$package}"
        );
    }
}
