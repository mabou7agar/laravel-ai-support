<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\SubAgents;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Services\Agent\AgentManifestService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;

/**
 * A skill registry that exposes NO skills — used for the domain sub-agent runtime.
 *
 * A domain agent (AiNativeSubAgentHandler) reasons over its own declared tools and must not
 * re-enter the global skill flow that delegated to it. If it inherits the global skills, the
 * skill matcher can match one from the agent's persona text — e.g. an "invoice" agent matches
 * the invoice-create skill — and the required-final-tool guard then refuses to finalize until
 * that skill's write tool (create_invoice) runs. A read-only question ("how much has X spent")
 * never calls that tool, so the run loops until its step budget is exhausted. With no skills,
 * nothing matches and the planner finalizes normally.
 *
 * Only skills() needs overriding (capabilityDocuments() delegates to it); providers() is
 * overridden too so a domain agent never auto-loads a global skill provider.
 */
final class EmptySkillRegistry extends AgentSkillRegistry
{
    public function __construct()
    {
        parent::__construct(app(Container::class), app(AgentManifestService::class));
    }

    public function skills(array $only = [], bool $includeDisabled = false): array
    {
        return [];
    }

    public function providers(array $only = []): array
    {
        return [];
    }
}
