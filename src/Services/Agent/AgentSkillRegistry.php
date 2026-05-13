<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Contracts\AgentSkillProvider;
use LaravelAIEngine\DTOs\AgentCapabilityDocument;
use LaravelAIEngine\DTOs\AgentSkillDefinition;
use RuntimeException;

class AgentSkillRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly AgentManifestService $manifestService
    ) {
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentSkillDefinition>
     */
    public function skills(array $only = [], bool $includeDisabled = false): array
    {
        if (!(bool) config('ai-agent.skills.enabled', true)) {
            return [];
        }

        $skills = [];

        foreach ($this->manifestService->skills() as $id => $definition) {
            $skill = $definition instanceof AgentSkillDefinition
                ? $definition
                : AgentSkillDefinition::fromArray(array_merge(['id' => $id], (array) $definition));

            $this->addSkill($skills, $skill, $only, $includeDisabled);
        }

        foreach ($this->providers() as $provider) {
            foreach ($provider->skills() as $definition) {
                $skill = $definition instanceof AgentSkillDefinition
                    ? $definition
                    : AgentSkillDefinition::fromArray((array) $definition);

                $this->addSkill($skills, $skill, $only, $includeDisabled);
            }
        }

        return array_values($skills);
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentCapabilityDocument>
     */
    public function capabilityDocuments(array $only = [], bool $includeDisabled = false): array
    {
        return array_map(
            static fn (AgentSkillDefinition $skill): AgentCapabilityDocument => $skill->capabilityDocument(),
            $this->skills($only, $includeDisabled)
        );
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentSkillProvider>
     */
    public function providers(array $only = []): array
    {
        $configured = array_merge(
            (array) config('ai-agent.skill_providers', []),
            $this->manifestService->skillProviders()
        );
        $only = array_values(array_filter(array_map('trim', $only)));
        $providers = [];

        foreach ($configured as $key => $class) {
            $name = is_string($key) ? $key : (string) $class;

            if ($only !== [] && !in_array($name, $only, true) && !in_array((string) $class, $only, true)) {
                continue;
            }

            $provider = $this->container->make($class);

            if (!$provider instanceof AgentSkillProvider) {
                throw new RuntimeException(sprintf(
                    'Agent skill provider [%s] must implement %s.',
                    (string) $class,
                    AgentSkillProvider::class
                ));
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * @param array<string, AgentSkillDefinition> $skills
     * @param array<int, string> $only
     */
    protected function addSkill(array &$skills, AgentSkillDefinition $skill, array $only, bool $includeDisabled): void
    {
        if ($skill->id === '' || trim($skill->name) === '' || trim($skill->description) === '') {
            return;
        }

        if (!$includeDisabled && !$skill->enabled) {
            return;
        }

        if ($only !== [] && !in_array($skill->id, $only, true) && !in_array($skill->name, $only, true)) {
            return;
        }

        $skills[$skill->id] = $skill;
    }
}
