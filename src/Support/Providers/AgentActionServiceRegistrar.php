<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentActionServiceRegistrar
{
    public static function register($app): void
    {
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionRegistry::class, function ($app) {
            $registry = new \LaravelAIEngine\Services\Actions\ActionRegistry();
            $registry->registerBatch((array) config('ai-agent.actions', []));
            $registry->registerProviders(array_merge(
                (array) config('ai-agent.action_providers', []),
                $app->make(\LaravelAIEngine\Services\Agent\AgentManifestService::class)->actionProviders()
            ));

            return $registry;
        });
        $app->singleton(\LaravelAIEngine\Services\Actions\ActionOrchestrator::class, fn ($app) => new \LaravelAIEngine\Services\Actions\ActionOrchestrator(
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            (array) config('ai-agent.action_relation_resolvers', []),
            $app->make(\LaravelAIEngine\Contracts\ConversationMemory::class),
            $app->make(\LaravelAIEngine\Contracts\ActionAuditLogger::class)
        ));
        $app->singleton(\LaravelAIEngine\Contracts\ActionFlowHandler::class, fn ($app) => new \LaravelAIEngine\Services\Actions\DefaultActionFlowHandler(
            $app->make(\LaravelAIEngine\Services\Actions\ActionRegistry::class),
            $app->make(\LaravelAIEngine\Services\Actions\ActionOrchestrator::class)
        ));
    }
}
