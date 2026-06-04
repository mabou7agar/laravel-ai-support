<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\ProviderTools;

/**
 * Resolves the "current owner" used to scope provider-tool API access.
 *
 * Ownership scoping is OPT-IN: the host app configures
 * `ai-engine.provider_tools.owner_resolver` as either a closure or a `Class@method`
 * string that returns the current owner id (e.g. the authenticated user or tenant id),
 * or null. When it resolves to null (the default), NO scoping is applied — single-tenant
 * deployments and apps that enforce their own authorization are unaffected. When it
 * resolves to an id, every run/approval/artifact lookup is constrained to that owner and
 * cross-owner access is refused (closing the IDOR on the provider-tool endpoints).
 */
class ProviderToolAccessScope
{
    public static function currentOwnerId(): ?string
    {
        $resolver = config('ai-engine.provider_tools.owner_resolver');

        $id = null;

        if ($resolver instanceof \Closure || (is_object($resolver) && is_callable($resolver))) {
            $id = $resolver();
        } elseif (is_string($resolver) && $resolver !== '') {
            if (str_contains($resolver, '@')) {
                [$class, $method] = explode('@', $resolver, 2);
                if (class_exists($class)) {
                    $id = app($class)->{$method}();
                }
            } elseif (is_callable($resolver)) {
                $id = $resolver();
            } elseif (class_exists($resolver)) {
                $instance = app($resolver);
                if (is_callable($instance)) {
                    $id = $instance();
                }
            }
        }

        return ($id === null || $id === '') ? null : (string) $id;
    }

    public static function enabled(): bool
    {
        return self::currentOwnerId() !== null;
    }
}
