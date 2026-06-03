<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Contracts\Federation\NodeMetadataProvider;

/**
 * Core node metadata provider.
 *
 * Wraps the local NodeMetadataDiscovery and the remote NodeRegistryService so
 * the IntentRouter no longer references those concrete node classes directly.
 * Behavior is byte-identical to the formerly inlined calls.
 */
class NodeMetadataProviderImpl implements NodeMetadataProvider
{
    protected ?NodeMetadataDiscovery $discovery;
    protected ?NodeRegistryService $nodeRegistry;

    public function __construct(
        ?NodeMetadataDiscovery $discovery = null,
        ?NodeRegistryService $nodeRegistry = null
    ) {
        $this->discovery = $discovery;
        $this->nodeRegistry = $nodeRegistry;
    }

    public function discover(): array
    {
        $discovery = $this->discovery ?? new NodeMetadataDiscovery();

        return $discovery->discover();
    }

    public function getActiveNodes(): array
    {
        if ($this->nodeRegistry === null) {
            return [];
        }

        $nodes = $this->nodeRegistry->getActiveNodes();

        return is_array($nodes) ? $nodes : $nodes->all();
    }
}
