<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Str;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Support\Infrastructure\InfrastructureHealthService;

class NodeManifestService
{
    public function __construct(
        protected NodeMetadataDiscovery $metadataDiscovery,
        protected AutonomousCollectorDiscoveryService $collectorDiscovery,
        protected ?InfrastructureHealthService $infrastructureHealth = null
    ) {
    }

    public function health(): array
    {
        $healthReport = $this->infrastructureHealthReport();
        $node = $this->localNode();

        return [
            'status' => $healthReport['status'] ?? 'healthy',
            'ready' => (bool) ($healthReport['ready'] ?? true),
            'version' => config('ai-engine.version', '1.0.0'),
            'name' => $node['name'],
            'url' => $node['url'],
            'node' => $node,
            'manifest_url' => url('/api/ai-engine/manifest'),
            'checks' => $healthReport['checks'] ?? [],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function manifest(): array
    {
        $metadata = $this->metadataDiscovery->discover();
        $collections = $this->normalizeCollections($metadata['collections'] ?? []);
        $collectors = $this->normalizeCollectors(
            $this->collectorDiscovery->discoverCollectors(useCache: false, includeRemote: false)
        );
        $node = $this->localNode();

        return [
            'node' => array_merge($node, [
                'version' => config('ai-engine.version', '1.0.0'),
                'description' => $metadata['description'] ?? '',
            ]),
            'capabilities' => array_values($metadata['capabilities'] ?? []),
            'domains' => array_values($metadata['domains'] ?? []),
            'data_types' => array_values($metadata['data_types'] ?? []),
            'keywords' => array_values($metadata['keywords'] ?? []),
            'collections' => $collections,
            'autonomous_collectors' => $collectors,
            'ownership' => [
                'collections' => array_values(array_unique(array_map(
                    fn (array $collection) => $collection['name'] ?? '',
                    $collections
                ))),
                'tools' => array_values(array_unique(array_map(
                    fn (array $collector) => $collector['name'] ?? '',
                    $collectors
                ))),
            ],
            'auth' => [
                'scheme' => 'jwt',
            ],
            'timestamp' => now()->toIso8601String(),
        ];
    }

    public function collections(): array
    {
        return $this->manifest()['collections'];
    }

    public function autonomousCollectors(): array
    {
        return $this->manifest()['autonomous_collectors'];
    }

    protected function normalizeCollections(array $collections): array
    {
        return array_values(array_map(function ($collection) {
            $name = is_array($collection)
                ? ($collection['name'] ?? class_basename($collection['class'] ?? 'collection'))
                : class_basename((string) $collection);

            $class = is_array($collection) ? ($collection['class'] ?? null) : (string) $collection;
            $displayName = is_array($collection)
                ? ($collection['display_name'] ?? ucfirst(str_replace('_', ' ', $name)))
                : ucfirst(str_replace('_', ' ', $name));

            return [
                'name' => strtolower((string) $name),
                'class' => $class,
                'display_name' => $displayName,
                'table' => is_array($collection) ? ($collection['table'] ?? 'unknown') : 'unknown',
                'description' => is_array($collection) ? ($collection['description'] ?? '') : '',
                'aliases' => is_array($collection) ? array_values((array) ($collection['aliases'] ?? [])) : [],
                'capabilities' => is_array($collection) ? ($collection['capabilities'] ?? []) : [],
            ];
        }, $collections));
    }

    protected function normalizeCollectors(array $collectors): array
    {
        $normalized = [];

        foreach ($collectors as $name => $collector) {
            if (!is_array($collector)) {
                continue;
            }

            $normalized[] = [
                'name' => $collector['name'] ?? (string) $name,
                'goal' => $collector['goal'] ?? '',
                'description' => $collector['description'] ?? ($collector['goal'] ?? ''),
            ];
        }

        return array_values($normalized);
    }

    protected function localNode(): array
    {
        $configuredName = trim((string) config('ai-engine.nodes.local.name', config('app.name', 'Laravel')));
        $configuredSlug = trim((string) config('ai-engine.nodes.local.slug', ''));
        $configuredLabel = trim((string) config(
            'ai-engine.nodes.local.label',
            (string) config('ai-engine.nodes.local.role', '')
        ));
        $aliases = config('ai-engine.nodes.local.aliases', []);
        $aliases = is_array($aliases) ? $aliases : [];
        $aliases = array_values(array_unique(array_filter(array_map(
            static fn ($alias) => trim((string) $alias),
            $aliases
        ))));

        $name = $configuredName !== '' ? $configuredName : config('app.name', 'Laravel');
        $slug = $configuredSlug !== '' ? $configuredSlug : Str::slug($name);
        $slug = $slug !== '' ? $slug : 'local';

        return [
            'slug' => $slug,
            'name' => $name,
            'label' => $configuredLabel !== '' ? $configuredLabel : (config('ai-engine.nodes.is_master', true) ? 'master' : 'client'),
            'role' => $configuredLabel !== '' ? $configuredLabel : (config('ai-engine.nodes.is_master', true) ? 'master' : 'client'),
            'type' => config('ai-engine.nodes.is_master', true) ? 'master' : 'child',
            'aliases' => $aliases,
            'url' => config('app.url'),
        ];
    }

    protected function infrastructureHealthReport(): array
    {
        if ($this->infrastructureHealth) {
            return $this->infrastructureHealth->evaluate();
        }

        if (app()->bound(InfrastructureHealthService::class)) {
            return app(InfrastructureHealthService::class)->evaluate();
        }

        return [
            'status' => 'healthy',
            'ready' => true,
            'checks' => [],
        ];
    }
}
