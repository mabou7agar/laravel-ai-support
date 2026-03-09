<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\Models\AINode;

class NodeOwnershipResolver
{
    public function __construct(protected NodeRegistryService $registry)
    {
    }

    public function resolveForCollection(string $collection): ?AINode
    {
        foreach ($this->collectionCandidates($collection) as $candidate) {
            $node = $this->registry->findNodeForCollection($candidate);
            if ($node) {
                return $node;
            }
        }

        return null;
    }

    public function resolveForCollections(array $collections): ?AINode
    {
        foreach ($collections as $collection) {
            if (!is_string($collection) || trim($collection) === '') {
                continue;
            }

            $node = $this->resolveForCollection($collection);
            if ($node) {
                return $node;
            }
        }

        return null;
    }

    public function collectionCandidates(string $collection): array
    {
        $normalized = trim($collection);
        if ($normalized === '') {
            return [];
        }

        $baseName = class_basename($normalized);
        $lower = strtolower($baseName);
        $singular = rtrim($lower, 's');
        $plural = $singular . 's';

        return array_values(array_unique(array_filter([
            $normalized,
            $baseName,
            $lower,
            $singular,
            $plural,
        ])));
    }
}
