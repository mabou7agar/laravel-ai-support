<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use LaravelAIEngine\Models\AINode;

class NodeBulkSyncService
{
    public function templatePayload(): array
    {
        return [
            'nodes' => [
                [
                    'name' => 'Billing Node',
                    'slug' => 'billing',
                    'type' => 'child',
                    'url' => 'https://billing.example.com',
                    'description' => 'Billing domain node',
                    'capabilities' => (array) config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']),
                    'status' => 'active',
                    'weight' => 1,
                ],
            ],
        ];
    }

    public function exportCurrentNodesPayload(): array
    {
        $nodes = AINode::query()
            ->orderBy('name')
            ->get();

        return [
            'nodes' => $nodes->map(function (AINode $node): array {
                return [
                    'name' => (string) $node->name,
                    'slug' => (string) $node->slug,
                    'type' => (string) ($node->type ?? 'child'),
                    'url' => (string) $node->url,
                    'description' => (string) ($node->description ?? ''),
                    'capabilities' => array_values((array) ($node->capabilities ?? [])),
                    'domains' => array_values((array) ($node->domains ?? [])),
                    'data_types' => array_values((array) ($node->data_types ?? [])),
                    'keywords' => array_values((array) ($node->keywords ?? [])),
                    'collections' => array_values((array) ($node->collections ?? [])),
                    'autonomous_collectors' => array_values((array) ($node->autonomous_collectors ?? [])),
                    'metadata' => (array) ($node->metadata ?? []),
                    'version' => (string) ($node->version ?? '1.0.0'),
                    'status' => (string) ($node->status ?? 'active'),
                    'weight' => (int) ($node->weight ?? 1),
                ];
            })->values()->all(),
        ];
    }

    public function resolveDefinitionFile(?string $inputPath = null): ?string
    {
        $candidates = array_values(array_filter([
            $inputPath,
            base_path('config/ai-engine-nodes.php'),
            base_path('config/ai-engine-nodes.json'),
        ]));

        foreach ($candidates as $candidate) {
            $path = $this->absolutePath((string) $candidate);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function absolutePath(string $path): string
    {
        if (Str::startsWith($path, ['/'])) {
            return $path;
        }

        return base_path($path);
    }

    public function loadDefinitionsFromFile(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $data = require $filePath;

            if (!is_array($data)) {
                throw new \RuntimeException('PHP file must return an array.');
            }

            return $data;
        }

        if ($extension === 'json') {
            $decoded = json_decode((string) file_get_contents($filePath), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('JSON file must decode to an object or array.');
            }

            return $decoded;
        }

        throw new \RuntimeException('Unsupported file extension. Use .php or .json');
    }

    public function loadDefinitionsFromJsonPayload(string $payload): array
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Payload must be a valid JSON object or array.');
        }

        return $decoded;
    }

    public function autoFixPayload(array $raw, bool $strict = false): array
    {
        $records = Arr::get($raw, 'nodes', $raw);
        if (!is_array($records)) {
            return [
                'payload' => ['nodes' => []],
                'mode' => $strict ? 'strict' : 'smart',
                'changes' => [[
                    'row' => 'root',
                    'field' => 'nodes',
                    'message' => 'Converted invalid payload root to an empty nodes list.',
                ]],
            ];
        }

        $defaultCapabilities = (array) config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']);
        $fixedRecords = [];
        $changes = [];
        $seenSlugs = [];

        foreach ($records as $key => $record) {
            $row = is_int($key) ? $key : (string) $key;

            if (!is_array($record)) {
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'record',
                    'message' => 'Dropped non-object row.',
                ];
                continue;
            }

            $fixed = $record;

            $name = trim((string) ($fixed['name'] ?? ''));
            $slug = Str::slug((string) ($fixed['slug'] ?? ''));

            if (!$strict && $name === '' && $slug !== '') {
                $fixed['name'] = Str::title(str_replace('-', ' ', $slug));
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'name',
                    'message' => 'Derived name from slug.',
                ];
            } elseif ($name !== (string) ($fixed['name'] ?? '')) {
                $fixed['name'] = $name;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'name',
                    'message' => 'Trimmed name whitespace.',
                ];
            }

            $slugSource = (string) ($fixed['slug'] ?? '');
            if ($slugSource === '' && !$strict) {
                $slugSource = (string) ($fixed['name'] ?? '');
            }
            $normalizedSlug = Str::slug($slugSource);
            if ($normalizedSlug !== '' && $normalizedSlug !== (string) ($fixed['slug'] ?? '')) {
                $fixed['slug'] = $normalizedSlug;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'slug',
                    'message' => 'Normalized slug format.',
                ];
            }

            if (($fixed['slug'] ?? '') !== '') {
                $original = (string) $fixed['slug'];
                $unique = $original;
                $suffix = 2;
                if (!$strict) {
                    while (isset($seenSlugs[$unique])) {
                        $unique = $original . '-' . $suffix;
                        $suffix++;
                    }

                    if ($unique !== $original) {
                        $fixed['slug'] = $unique;
                        $changes[] = [
                            'row' => (string) $row,
                            'field' => 'slug',
                            'message' => 'Adjusted duplicate slug to `' . $unique . '`.',
                        ];
                    }
                }

                $seenSlugs[(string) $fixed['slug']] = true;
            }

            $url = trim((string) ($fixed['url'] ?? ''));
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL) && !str_contains($url, '://')) {
                $candidate = 'https://' . ltrim($url, '/');
                if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $fixed['url'] = $candidate;
                    $changes[] = [
                        'row' => (string) $row,
                        'field' => 'url',
                        'message' => 'Prefixed URL with https://',
                    ];
                }
            } elseif ($url !== (string) ($fixed['url'] ?? '')) {
                $fixed['url'] = $url;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'url',
                    'message' => 'Trimmed URL whitespace.',
                ];
            }

            $type = (string) ($fixed['type'] ?? 'child');
            if (!$strict && !in_array($type, ['master', 'child'], true)) {
                $fixed['type'] = 'child';
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'type',
                    'message' => 'Reset invalid type to `child`.',
                ];
            }

            $status = (string) ($fixed['status'] ?? 'active');
            if (!$strict && !in_array($status, ['active', 'inactive', 'error'], true)) {
                $fixed['status'] = 'active';
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'status',
                    'message' => 'Reset invalid status to `active`.',
                ];
            }

            if (isset($fixed['capabilities']) && is_string($fixed['capabilities'])) {
                $fixed['capabilities'] = array_values(array_filter(array_map(
                    static fn (string $item): string => trim($item),
                    explode(',', (string) $fixed['capabilities'])
                )));
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'capabilities',
                    'message' => 'Converted capabilities string to array.',
                ];
            }

            if (
                !$strict
                && (!isset($fixed['capabilities']) || !is_array($fixed['capabilities']) || $fixed['capabilities'] === [])
            ) {
                $fixed['capabilities'] = $defaultCapabilities;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'capabilities',
                    'message' => 'Applied default capabilities.',
                ];
            }

            $weight = (int) ($fixed['weight'] ?? 1);
            if ($weight < 1) {
                $fixed['weight'] = 1;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'weight',
                    'message' => 'Raised weight to minimum value 1.',
                ];
            } elseif (!isset($fixed['weight']) || (int) $fixed['weight'] !== $weight) {
                $fixed['weight'] = $weight;
                $changes[] = [
                    'row' => (string) $row,
                    'field' => 'weight',
                    'message' => 'Normalized weight to integer.',
                ];
            }

            foreach (['domains', 'data_types', 'keywords', 'collections', 'autonomous_collectors'] as $arrayField) {
                if (isset($fixed[$arrayField]) && !is_array($fixed[$arrayField])) {
                    $fixed[$arrayField] = is_string($fixed[$arrayField])
                        ? array_values(array_filter(array_map(
                            static fn (string $item): string => trim($item),
                            explode(',', (string) $fixed[$arrayField])
                        )))
                        : [];
                    $changes[] = [
                        'row' => (string) $row,
                        'field' => $arrayField,
                        'message' => 'Normalized value to array.',
                    ];
                }
            }

            $fixedRecords[] = $fixed;
        }

        return [
            'payload' => ['nodes' => array_values($fixedRecords)],
            'mode' => $strict ? 'strict' : 'smart',
            'changes' => $changes,
        ];
    }

    public function normalizeDefinitions(array $raw): array
    {
        return $this->normalizeDefinitionsWithDiagnostics($raw)['definitions'];
    }

    public function normalizeDefinitionsWithDiagnostics(array $raw): array
    {
        $records = Arr::get($raw, 'nodes', $raw);
        if (!is_array($records)) {
            return [
                'definitions' => [],
                'invalid' => [$this->invalidRow(
                    'root',
                    null,
                    'Payload must be an array or contain a "nodes" array.',
                    'Wrap payload as {"nodes":[...]} or pass a raw array of node objects.'
                )],
            ];
        }

        $defaultCapabilities = (array) config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']);
        $normalized = [];
        $seenSlugs = [];
        $invalid = [];

        foreach ($records as $key => $record) {
            $row = is_int($key) ? $key : (string) $key;

            if (!is_array($record)) {
                $invalid[] = $this->invalidRow(
                    $row,
                    null,
                    'Node definition must be an object.',
                    'Replace this row with a JSON object like {"name":"...", "url":"https://..."}.'
                );
                continue;
            }

            $name = trim((string) ($record['name'] ?? ''));
            $url = trim((string) ($record['url'] ?? ''));
            $slugSource = $record['slug'] ?? (is_string($key) ? $key : $name);
            $slug = Str::slug((string) $slugSource);

            if ($name === '') {
                $invalid[] = $this->invalidRow(
                    $row,
                    $slug !== '' ? $slug : null,
                    'Missing required field: name.',
                    'Add a non-empty "name" value for this node.'
                );
                continue;
            }

            if ($url === '') {
                $invalid[] = $this->invalidRow(
                    $row,
                    $slug !== '' ? $slug : null,
                    'Missing required field: url.',
                    'Set "url" to an absolute endpoint, e.g. "https://billing.example.com".'
                );
                continue;
            }

            if ($slug === '') {
                $invalid[] = $this->invalidRow(
                    $row,
                    null,
                    'Unable to compute slug from name/slug.',
                    'Provide a valid "slug" (letters/numbers/hyphen) or a name that can generate one.'
                );
                continue;
            }

            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $invalid[] = $this->invalidRow(
                    $row,
                    $slug,
                    'Invalid URL format.',
                    'Use a valid absolute URL starting with http:// or https://.'
                );
                continue;
            }

            if (isset($seenSlugs[$slug])) {
                $invalid[] = $this->invalidRow(
                    $row,
                    $slug,
                    'Duplicate slug in payload.',
                    'Use a unique slug per node row.'
                );
                continue;
            }

            $seenSlugs[$slug] = true;

            $normalized[] = [
                'name' => $name,
                'slug' => $slug,
                'type' => (string) ($record['type'] ?? 'child'),
                'url' => $url,
                'description' => (string) ($record['description'] ?? ''),
                'capabilities' => array_values((array) ($record['capabilities'] ?? $defaultCapabilities)),
                'domains' => array_values((array) ($record['domains'] ?? [])),
                'data_types' => array_values((array) ($record['data_types'] ?? [])),
                'keywords' => array_values((array) ($record['keywords'] ?? [])),
                'collections' => array_values((array) ($record['collections'] ?? [])),
                'autonomous_collectors' => array_values((array) ($record['autonomous_collectors'] ?? [])),
                'metadata' => (array) ($record['metadata'] ?? []),
                'version' => (string) ($record['version'] ?? '1.0.0'),
                'status' => (string) ($record['status'] ?? 'active'),
                'weight' => (int) ($record['weight'] ?? 1),
                'api_key' => $record['api_key'] ?? null,
            ];
        }

        return [
            'definitions' => $normalized,
            'invalid' => $invalid,
        ];
    }

    public function buildPlan(array $definitions): array
    {
        $plan = [
            'create' => [],
            'update' => [],
            'unchanged' => [],
            'invalid' => [],
            'desired_slugs' => [],
        ];

        foreach ($definitions as $definition) {
            $slug = (string) ($definition['slug'] ?? '');
            if ($slug === '') {
                continue;
            }

            $plan['desired_slugs'][] = $slug;

            $existing = AINode::query()->where('slug', $slug)->first();
            if (!$existing) {
                $plan['create'][] = $definition;
                continue;
            }

            $changes = $this->calculateChanges($existing, $definition);
            if ($changes === []) {
                $plan['unchanged'][] = $definition + ['id' => $existing->id];
                continue;
            }

            $plan['update'][] = [
                'id' => $existing->id,
                'slug' => $slug,
                'name' => $definition['name'] ?? $existing->name,
                'changes' => $changes,
                'payload' => $definition,
            ];
        }

        $plan['desired_slugs'] = array_values(array_unique($plan['desired_slugs']));

        return $plan;
    }

    public function summarizePlan(array $plan, ?string $source = null): array
    {
        return [
            'source' => $source,
            'create' => count($plan['create'] ?? []),
            'update' => count($plan['update'] ?? []),
            'unchanged' => count($plan['unchanged'] ?? []),
            'invalid' => count($plan['invalid'] ?? []),
            'desired_slugs' => count($plan['desired_slugs'] ?? []),
        ];
    }

    public function applyPlan(array $plan, bool $prune): array
    {
        $created = 0;
        $updated = 0;
        $deactivated = 0;
        $touchedSlugs = [];

        foreach (($plan['create'] ?? []) as $payload) {
            if (($payload['api_key'] ?? null) === null) {
                unset($payload['api_key']);
            }

            AINode::query()->create($payload);
            $created++;
            $touchedSlugs[] = (string) ($payload['slug'] ?? '');
        }

        foreach (($plan['update'] ?? []) as $row) {
            /** @var AINode|null $node */
            $node = AINode::query()->find($row['id'] ?? null);
            if (!$node) {
                continue;
            }

            $payload = (array) ($row['payload'] ?? []);
            if (($payload['api_key'] ?? null) === null) {
                unset($payload['api_key']);
            }

            $node->fill($payload);
            if ($node->isDirty()) {
                $node->save();
                $updated++;
                $touchedSlugs[] = $node->slug;
            }
        }

        if ($prune) {
            $desired = (array) ($plan['desired_slugs'] ?? []);

            $staleNodes = AINode::query()
                ->where('type', 'child')
                ->whereNotIn('slug', $desired)
                ->where('status', '!=', 'inactive')
                ->get();

            foreach ($staleNodes as $staleNode) {
                $staleNode->update(['status' => 'inactive']);
                $deactivated++;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'deactivated' => $deactivated,
            'touched_slugs' => array_values(array_unique(array_filter($touchedSlugs))),
        ];
    }

    public function pingTouchedNodes(array $slugs, NodeRegistryService $registry): array
    {
        $results = [];

        foreach (array_values(array_unique($slugs)) as $slug) {
            $node = AINode::query()->where('slug', (string) $slug)->first();
            if (!$node) {
                continue;
            }

            $results[(string) $slug] = $registry->ping($node);
        }

        return $results;
    }

    protected function calculateChanges(AINode $existing, array $incoming): array
    {
        $changes = [];

        foreach ($incoming as $field => $value) {
            if ($field === 'api_key' && $value === null) {
                continue;
            }

            $current = $existing->{$field};
            if ($this->valuesDiffer($current, $value)) {
                $changes[$field] = [
                    'from' => $current,
                    'to' => $value,
                ];
            }
        }

        return $changes;
    }

    protected function valuesDiffer($left, $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return $this->normalizeForComparison((array) $left) !== $this->normalizeForComparison((array) $right);
        }

        return (string) $left !== (string) $right;
    }

    protected function normalizeForComparison(array $value): array
    {
        ksort($value);
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->normalizeForComparison($item);
            }
        }
        unset($item);

        return $value;
    }

    protected function invalidRow(int|string $row, ?string $slug, string $reason, string $suggestion): array
    {
        return [
            'row' => (string) $row,
            'slug' => $slug,
            'reason' => $reason,
            'suggestion' => $suggestion,
        ];
    }
}
