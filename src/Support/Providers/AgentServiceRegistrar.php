<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support\Providers;

class AgentServiceRegistrar
{
    public static function register($app): void
    {
        AgentCoreServiceRegistrar::register($app);
        AgentToolServiceRegistrar::register($app);
        AgentRagServiceRegistrar::register($app);
        AgentActionServiceRegistrar::register($app);
        AgentRuntimeServiceRegistrar::register($app);
    }
}
