<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Contracts\Container\Container;
use LaravelAIEngine\Contracts\AgentCapabilityProvider;
use LaravelAIEngine\DTOs\AgentCapabilityDocument;
use RuntimeException;

class AgentCapabilityRegistry
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentCapabilityDocument>
     */
    public function documents(array $only = []): array
    {
        $documents = [];

        foreach ($this->providers($only) as $provider) {
            foreach ($provider->capabilities() as $document) {
                $document = $document instanceof AgentCapabilityDocument
                    ? $document
                    : AgentCapabilityDocument::fromArray((array) $document);

                if ($document->id === '' || trim($document->text) === '') {
                    continue;
                }

                $documents[$document->id] = $document;
            }
        }

        foreach ($this->skillDocuments($only) as $document) {
            if ($document->id === '' || trim($document->text) === '') {
                continue;
            }

            $documents[$document->id] = $document;
        }

        return array_values($documents);
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentCapabilityProvider>
     */
    public function providers(array $only = []): array
    {
        $configured = (array) config('ai-agent.capability_providers', []);
        $only = array_values(array_filter(array_map('trim', $only)));
        $providers = [];

        foreach ($configured as $key => $class) {
            $name = is_string($key) ? $key : (string) $class;

            if ($only !== [] && !in_array($name, $only, true) && !in_array((string) $class, $only, true)) {
                continue;
            }

            $provider = $this->container->make($class);

            if (!$provider instanceof AgentCapabilityProvider) {
                throw new RuntimeException(sprintf(
                    'Agent capability provider [%s] must implement %s.',
                    (string) $class,
                    AgentCapabilityProvider::class
                ));
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    /**
     * @param array<int, string> $only
     * @return array<int, AgentCapabilityDocument>
     */
    protected function skillDocuments(array $only): array
    {
        if (!(bool) config('ai-agent.skills.expose_as_capabilities', true)) {
            return [];
        }

        try {
            return $this->container
                ->make(AgentSkillRegistry::class)
                ->capabilityDocuments($only);
        } catch (\Throwable) {
            return [];
        }
    }
}
