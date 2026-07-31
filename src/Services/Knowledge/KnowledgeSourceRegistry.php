<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Knowledge;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Config\Repository;
use LaravelAIEngine\Contracts\KnowledgeAccessPolicy;
use LaravelAIEngine\Contracts\KnowledgeSourceProvider;
use LaravelAIEngine\DTOs\ScopedKnowledgeDocument;
use RuntimeException;

final class KnowledgeSourceRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly Repository $config,
        private readonly KnowledgeAccessPolicy $access,
    ) {
    }

    /** @param array<string, mixed> $context @return list<ScopedKnowledgeDocument> */
    public function documents(array $context = []): array
    {
        $documents = [];
        foreach ($this->providers() as $provider) {
            foreach ($provider->documents($context) as $value) {
                $document = $value instanceof ScopedKnowledgeDocument
                    ? $value
                    : ScopedKnowledgeDocument::fromArray((array) $value);
                if ($document->id === '' || $document->text === '' || !$this->access->canAccess($document, $context)) {
                    continue;
                }
                $documents[$document->id] = $document;
            }
        }

        return array_values($documents);
    }

    /** @return list<KnowledgeSourceProvider> */
    public function providers(): array
    {
        $providers = [];
        foreach ((array) $this->config->get('ai-agent.assistant.knowledge_sources', []) as $class) {
            if (!is_string($class) || trim($class) === '') {
                continue;
            }
            $provider = $this->container->make($class);
            if (!$provider instanceof KnowledgeSourceProvider) {
                throw new RuntimeException(sprintf(
                    'Knowledge source provider [%s] must implement %s.',
                    $class,
                    KnowledgeSourceProvider::class,
                ));
            }
            $providers[] = $provider;
        }

        return $providers;
    }
}
