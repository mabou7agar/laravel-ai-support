<?php

namespace LaravelAIEngine\Services\Agent\Traits;

use LaravelAIEngine\Builders\WorkflowConfigBuilder;

trait HasWorkflowConfig
{
    /**
     * Create a new workflow config builder
     */
    protected function workflowConfig(): WorkflowConfigBuilder
    {
        return WorkflowConfigBuilder::make();
    }
}
