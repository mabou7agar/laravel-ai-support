<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Concerns;

/**
 * Marks a test as requiring the optional laravel-ai-engine-federation package.
 * Core does NOT depend on federation; these node-integration tests are skipped
 * when it isn't installed (run `composer require --dev m-tech-stack/laravel-ai-engine-federation`
 * to enable them). CI installs federation, so it runs them.
 */
trait RequiresFederation
{
    /**
     * @before
     */
    public function skipWhenFederationMissing(): void
    {
        if (!class_exists(\LaravelAIEngine\Services\Agent\NodeSessionManager::class)) {
            $this->markTestSkipped(
                'Requires the optional laravel-ai-engine-federation package '
                . '(composer require --dev m-tech-stack/laravel-ai-engine-federation).'
            );
        }
    }
}
