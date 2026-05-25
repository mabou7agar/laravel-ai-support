<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class AdminNodeOperationsService
{
    public function index(NodeRegistryService $registry, NodeAdminService $nodeAdmin): View
    {
        $tableExists = Schema::hasTable('ai_nodes');

        return view('ai-engine::admin.nodes', [
            'table_exists' => $tableExists,
            'stats' => $tableExists ? $registry->getStatistics() : $this->emptyStats(),
            'nodes' => $tableExists ? $nodeAdmin->recentNodes() : collect(),
            'default_capabilities' => config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag']),
            'is_master_node' => $this->isMasterNode(),
            'default_autofix_strict' => $this->defaultAutofixStrict(),
        ]);
    }

    public function register(array $validated, NodeRegistryService $registry): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $node = $registry->register([
            'name' => $validated['name'],
            'slug' => $validated['slug'] ?? null,
            'type' => $validated['type'],
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'capabilities' => $this->parseCsvList(
                $validated['capabilities'] ?? null,
                (array) config('ai-engine.nodes.capabilities', ['search', 'actions', 'rag'])
            ),
            'weight' => (int) ($validated['weight'] ?? 1),
            'api_key' => $this->normalizeNullableString($validated['api_key'] ?? null),
        ]);

        $postCreateStatus = (string) ($validated['status'] ?? '');
        if ($postCreateStatus !== '' && $postCreateStatus !== 'active') {
            $registry->updateStatus($node, $postCreateStatus);
            $node->refresh();
        }

        return back()->with('status', 'Node `' . $node->slug . '` registered.');
    }

    public function update(array $validated, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $nodeId = (int) $validated['node_id'];
        $node = $nodeAdmin->findNode($nodeId);
        if (!$node) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        $node = $nodeAdmin->updateNode($nodeId, $validated);

        return back()->with('status', 'Node `' . $node->slug . '` updated.');
    }

    public function updateStatus(array $validated, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $node = $nodeAdmin->setStatus((int) $validated['node_id'], (string) $validated['status']);
        if (!$node) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        return back()->with('status', 'Node `' . $node->slug . '` status set to `' . $validated['status'] . '`.');
    }

    public function ping(array $validated, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $result = $nodeAdmin->ping((int) $validated['node_id']);
        if (!$result) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        $node = $result['node'];

        return back()->with('status', (bool) $result['success']
            ? ('Ping succeeded for `' . $node->slug . '`.')
            : ('Ping failed for `' . $node->slug . '`.')
        );
    }

    public function pingAll(NodeRegistryService $registry): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $results = $registry->pingAll();
        $successCount = count(array_filter($results, static fn (array $item): bool => (bool) ($item['success'] ?? false)));
        $total = count($results);

        return back()->with('status', 'Pinged ' . $total . ' node(s): ' . $successCount . ' healthy, ' . ($total - $successCount) . ' failed.');
    }

    public function delete(array $validated, NodeAdminService $nodeAdmin): RedirectResponse
    {
        if (!Schema::hasTable('ai_nodes')) {
            return back()->withErrors(['nodes' => '`ai_nodes` table is missing. Run package migrations first.']);
        }

        $slug = $nodeAdmin->delete((int) $validated['node_id']);
        if ($slug === null) {
            return back()->withErrors(['nodes' => 'Node not found.']);
        }

        return back()->with('status', 'Node `' . $slug . '` removed.');
    }

    public function isMasterNode(): bool
    {
        return (bool) config('ai-engine.nodes.is_master', true);
    }

    public function defaultAutofixStrict(): bool
    {
        return strtolower((string) config('ai-engine.nodes.bulk_sync.autofix_mode', 'smart')) === 'strict';
    }

    private function emptyStats(): array
    {
        return [
            'total' => 0,
            'active' => 0,
            'inactive' => 0,
            'error' => 0,
            'healthy' => 0,
            'by_type' => [],
            'avg_response_time' => null,
        ];
    }

    private function parseCsvList(?string $value, array $default = []): array
    {
        if (!is_string($value) || trim($value) === '') {
            return array_values(array_unique(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                $default
            ))));
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value)
        ))));
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
