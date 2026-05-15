<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Graph;

use Illuminate\Support\Str;

class GraphOntologyService
{
    public function relationTypeFor(string $relationName, ?string $sourceModelClass = null, ?string $targetModelClass = null): ?string
    {
        $normalized = $this->normalizeToken($relationName);
        if ($normalized === '') {
            return null;
        }

        $aliases = $this->relationAliases();
        foreach ($aliases as $type => $tokens) {
            foreach ((array) $tokens as $token) {
                $token = $this->normalizeToken((string) $token);
                if ($normalized === $token || str_contains($normalized, $token) || str_contains($token, $normalized)) {
                    return strtoupper((string) $type);
                }
            }
        }

        $targetModelType = $this->modelTypeForClass($targetModelClass);
        if ($targetModelType !== null) {
            $targetSpecific = [
                'user' => 'HAS_USER',
                'mail' => 'HAS_MAIL',
                'email' => 'HAS_MAIL',
                'task' => 'HAS_TASK',
                'project' => 'HAS_PROJECT',
                'workspace' => 'HAS_WORKSPACE',
                'ticket' => 'HAS_TICKET',
                'issue' => 'HAS_ISSUE',
                'document' => 'HAS_DOCUMENT',
                'contact' => 'HAS_CONTACT',
                'company' => 'HAS_COMPANY',
            ];

            if (isset($targetSpecific[$targetModelType])) {
                return $targetSpecific[$targetModelType];
            }
        }

        $sourceModelType = $this->modelTypeForClass($sourceModelClass);
        $pairSpecific = $this->pairSpecificRelationType($normalized, $sourceModelType, $targetModelType);
        if ($pairSpecific !== null) {
            return $pairSpecific;
        }

        return null;
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    public function relationTypesForQuery(string $query, array $collections = []): array
    {
        $normalized = $this->normalizeToken($query);
        $types = $this->relationTypesForCollections($collections);

        foreach ($this->relationAliases() as $type => $tokens) {
            foreach ((array) $tokens as $token) {
                $token = $this->normalizeToken((string) $token);
                if ($token !== '' && str_contains($normalized, $token)) {
                    $types[] = strtoupper((string) $type);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($types)));
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    public function preferredModelTypesForQuery(string $query, array $collections = []): array
    {
        $normalized = $this->normalizeToken($query);
        $types = $this->preferredModelTypesForCollections($collections);

        foreach ($this->allKnownModelTypes() as $type) {
            if (str_contains($normalized, $type)) {
                $types[] = $type;
            }

            foreach ((array) data_get($this->modelAliases(), $type, []) as $alias) {
                $alias = $this->normalizeToken((string) $alias);
                if ($alias !== '' && str_contains($normalized, $alias)) {
                    $types[] = $type;
                    $types[] = $alias;
                }
            }
        }

        return array_values(array_unique(array_filter($types)));
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    public function preferredModelTypesForCollections(array $collections): array
    {
        $types = [];
        foreach ($collections as $collection) {
            $type = $this->modelTypeForClass($collection);
            if ($type === null) {
                continue;
            }

            $types[] = $type;
            foreach ((array) data_get($this->modelAliases(), $type, []) as $alias) {
                $alias = $this->normalizeToken((string) $alias);
                if ($alias !== '') {
                    $types[] = $alias;
                }
            }
        }

        return array_values(array_unique(array_filter($types)));
    }

    /**
     * @param array<int, string> $collections
     * @return array<int, string>
     */
    public function relationTypesForCollections(array $collections): array
    {
        $types = [];
        foreach ($this->preferredModelTypesForCollections($collections) as $type) {
            foreach ((array) data_get($this->modelRelationTypes(), $type, []) as $relationType) {
                $relationType = strtoupper(trim((string) $relationType));
                if ($relationType !== '') {
                    $types[] = $relationType;
                }
            }
        }

        return array_values(array_unique($types));
    }

    public function modelTypeForClass(?string $class): ?string
    {
        if (!is_string($class) || trim($class) === '') {
            return null;
        }

        $base = $this->normalizeToken(Str::snake(class_basename($class)));
        if ($base === '') {
            return null;
        }

        $knownTypes = array_values(array_unique(array_merge(
            array_keys($this->modelAliases()),
            array_keys($this->modelRelationTypes()),
            ['user', 'mail', 'email', 'message', 'comment', 'task', 'project', 'workspace', 'ticket', 'issue', 'document', 'contact', 'company', 'organization', 'team', 'account', 'folder', 'channel', 'thread', 'milestone', 'sprint']
        )));

        foreach ($this->candidateModelTypes($base) as $candidate) {
            if (in_array($candidate, $knownTypes, true)) {
                return $candidate;
            }
        }

        if (str_ends_with($base, 'ies')) {
            return substr($base, 0, -3) . 'y';
        }

        if (str_ends_with($base, 's') && strlen($base) > 3) {
            return substr($base, 0, -1);
        }

        return $base;
    }

    /**
     * @return array<int, string>
     */
    protected function candidateModelTypes(string $base): array
    {
        $candidates = [$base];
        if (str_ends_with($base, '_model')) {
            $candidates[] = substr($base, 0, -6);
        }

        foreach ($candidates as $candidate) {
            $parts = array_values(array_filter(explode('_', $candidate)));
            $count = count($parts);
            for ($i = 0; $i < $count; $i++) {
                $suffix = implode('_', array_slice($parts, $i));
                if ($suffix !== '') {
                    $candidates[] = $suffix;
                }
            }
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeToken($candidate);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
            if (str_ends_with($candidate, 'ies')) {
                $normalized[] = substr($candidate, 0, -3) . 'y';
            } elseif (str_ends_with($candidate, 's') && strlen($candidate) > 3) {
                $normalized[] = substr($candidate, 0, -1);
            }
        }

        return array_values(array_unique($normalized));
    }

    protected function normalizeToken(string $token): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $token) ?? ''));
    }

    protected function pairSpecificRelationType(string $relationName, ?string $sourceModelType, ?string $targetModelType): ?string
    {
        if ($targetModelType === null) {
            return null;
        }

        return match (true) {
            in_array($targetModelType, ['thread', 'channel'], true) => 'IN_' . strtoupper($targetModelType),
            in_array($targetModelType, ['milestone', 'sprint', 'workspace', 'project', 'organization', 'team', 'account', 'folder'], true) => 'IN_' . strtoupper($targetModelType),
            $targetModelType === 'user' && str_contains($relationName, 'watch') => 'WATCHED_BY',
            $targetModelType === 'user' && str_contains($relationName, 'mention') => 'MENTIONS',
            $targetModelType === 'user' && str_contains($relationName, 'own') => 'OWNED_BY',
            $targetModelType === 'user' && str_contains($relationName, 'assign') => 'ASSIGNED_TO',
            $targetModelType === 'user' && str_contains($relationName, 'manag') => 'MANAGED_BY',
            $targetModelType === 'user' && str_contains($relationName, 'report') => 'REPORTED_BY',
            $targetModelType === 'contact' && str_contains($relationName, 'customer') => 'FOR_CUSTOMER',
            $targetModelType === 'company' && str_contains($relationName, 'vendor') => 'FOR_VENDOR',
            $targetModelType === 'mail' && str_contains($relationName, 'reply') => 'REPLIED_TO',
            $targetModelType === 'mail' && str_contains($relationName, 'send') => 'SENT_TO',
            $targetModelType === 'document' && str_contains($relationName, 'attach') => 'HAS_ATTACHMENT',
            $targetModelType === 'issue' && str_contains($relationName, 'block') => 'BLOCKED_BY',
            $targetModelType === 'task' && str_contains($relationName, 'depend') => 'DEPENDS_ON',
            $sourceModelType === 'mail' && $targetModelType === 'user' => 'SENT_TO',
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    protected function allKnownModelTypes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys($this->modelAliases()),
            array_keys($this->modelRelationTypes()),
            ['user', 'mail', 'email', 'message', 'comment', 'task', 'project', 'workspace', 'ticket', 'issue', 'document', 'contact', 'company', 'organization', 'team', 'account', 'folder', 'channel', 'thread', 'milestone', 'sprint']
        )));
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function modelAliases(): array
    {
        return $this->mergePackMap('model_aliases');
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function modelRelationTypes(): array
    {
        return $this->mergePackMap('model_relation_types');
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function relationAliases(): array
    {
        return $this->mergePackMap('relation_aliases');
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function mergePackMap(string $key): array
    {
        $base = (array) config("ai-engine.graph.ontology.{$key}", []);

        foreach ($this->enabledPacks() as $pack) {
            $packMap = (array) data_get($this->ontologyPacks(), "{$pack}.{$key}", []);
            foreach ($packMap as $name => $values) {
                $current = array_values((array) ($base[$name] ?? []));
                $base[$name] = array_values(array_unique(array_merge($current, array_values((array) $values))));
            }
        }

        return $base;
    }

    /**
     * @return array<int, string>
     */
    protected function enabledPacks(): array
    {
        return array_values(array_filter(array_map(
            fn ($pack) => $this->normalizeToken((string) $pack),
            (array) config('ai-engine.graph.ontology.enabled_packs', [])
        )));
    }

    /**
     * @return array<string, array<string, array<string, array<int, string>>>>
     */
    protected function ontologyPacks(): array
    {
        return (array) config('ai-engine.graph.ontology.packs', []);
    }
}
