<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeForwarder;
use LaravelAIEngine\Services\Node\NodeRegistryService;

/**
 * Resolves and executes tools that live on remote nodes.
 *
 * Responsibilities:
 *  - Discover tools advertised by remote nodes (from autonomous_collectors metadata)
 *  - Build remote tool entries for the unified registry
 *  - Execute remote tools via NodeForwarder action forwarding
 *  - Handle cross-node dependencies (e.g. "create invoice for customer John"
 *    where customers live on node-A and invoices on node-B)
 *
 * The agent sees local and remote tools in one flat list. When it calls a
 * remote tool, this resolver transparently forwards the request to the
 * owning node.
 */
class CrossNodeToolResolver
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected NodeForwarder $forwarder
    ) {
    }

    // ──────────────────────────────────────────────
    //  Remote tool discovery
    // ──────────────────────────────────────────────

    /**
     * Build tool registry entries from remote nodes.
     *
     * Reads autonomous_collectors and tools advertised in node metadata
     * (synced via health pings). Each remote tool gets source=remote
     * and a node_slug so the executor knows where to forward.
     *
     * @return array<string, array> Same shape as AgentToolHandler::buildRegistry
     */
    public function buildRemoteRegistry(): array
    {
        $registry = [];

        try {
            $nodes = $this->registry->getActiveNodes();
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('CrossNodeToolResolver: failed loading nodes', [
                'error' => $e->getMessage(),
            ]);
            return $registry;
        }

        foreach ($nodes as $node) {
            // 1. Tools from autonomous_collectors
            $collectors = $node->autonomous_collectors ?? [];
            if (is_array($collectors)) {
                foreach ($collectors as $collector) {
                    if (!is_array($collector)) {
                        continue;
                    }
                    $name = $collector['name'] ?? null;
                    if (!$name) {
                        continue;
                    }

                    $registry[$name] = [
                        'handler' => null, // Remote — no local handler
                        'description' => $collector['description'] ?? $collector['goal'] ?? "Remote action on {$node->name}",
                        'parameters' => $collector['parameters'] ?? [],
                        'model' => $collector['model'] ?? $node->slug,
                        'config_class' => null,
                        'source' => 'remote',
                        'node_slug' => $node->slug,
                        'node_id' => $node->id,
                    ];
                }
            }

            // 2. Tools from node metadata (if nodes expose a tools array)
            $nodeTools = $node->tools ?? [];
            if (is_array($nodeTools)) {
                foreach ($nodeTools as $toolName => $toolDef) {
                    if (!is_string($toolName) || isset($registry[$toolName])) {
                        continue;
                    }

                    $registry[$toolName] = [
                        'handler' => null,
                        'description' => $toolDef['description'] ?? "Remote tool on {$node->name}",
                        'parameters' => $toolDef['parameters'] ?? [],
                        'model' => $toolDef['model'] ?? $node->slug,
                        'config_class' => null,
                        'source' => 'remote',
                        'node_slug' => $node->slug,
                        'node_id' => $node->id,
                    ];
                }
            }

            // 3. Implicit search tools for each collection the node owns
            $collections = $node->collections ?? [];
            if (is_array($collections)) {
                foreach ($collections as $collection) {
                    $collName = is_array($collection) ? ($collection['name'] ?? '') : strtolower(class_basename($collection));
                    if ($collName === '') {
                        continue;
                    }

                    $searchToolName = "search_{$collName}";
                    if (isset($registry[$searchToolName])) {
                        continue;
                    }

                    $registry[$searchToolName] = [
                        'handler' => null,
                        'description' => "Search {$collName} data on {$node->name}",
                        'parameters' => [
                            'query' => ['type' => 'string', 'required' => true, 'description' => 'Search query'],
                            'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Max results (default 10)'],
                        ],
                        'model' => $collName,
                        'config_class' => null,
                        'source' => 'remote',
                        'node_slug' => $node->slug,
                        'node_id' => $node->id,
                        '_type' => 'search', // Internal marker for routing
                        '_collection' => is_array($collection) ? ($collection['class'] ?? $collName) : $collection,
                    ];
                }
            }
        }

        Log::channel('ai-engine')->debug('CrossNodeToolResolver: built remote registry', [
            'tool_count' => count($registry),
            'tools' => array_keys($registry),
        ]);

        return $registry;
    }

    // ──────────────────────────────────────────────
    //  Remote tool execution
    // ──────────────────────────────────────────────

    /**
     * Execute a tool on a remote node.
     *
     * Routes to the correct forwarding method based on tool type:
     *  - search tools → forwardSearch
     *  - action tools → forwardAction (chat-based for collectors)
     *
     * @param array                $toolDef  Tool definition from registry (source=remote)
     * @param array                $params   Parameters from the agent
     * @param UnifiedActionContext $context  Session context
     * @return string Observation text for the reasoning loop
     */
    public function execute(array $toolDef, array $params, UnifiedActionContext $context): string
    {
        $nodeSlug = $toolDef['node_slug'] ?? null;
        if (!$nodeSlug) {
            return 'Error: Remote tool has no node_slug.';
        }

        $node = $this->registry->getNode($nodeSlug);
        if (!$node) {
            return "Error: Node '{$nodeSlug}' not found or unavailable.";
        }

        if (!$this->forwarder->isAvailable($node)) {
            return "Error: Node '{$nodeSlug}' is currently unavailable.";
        }

        $toolType = $toolDef['_type'] ?? 'action';

        try {
            if ($toolType === 'search') {
                return $this->executeRemoteSearch($node, $toolDef, $params, $context);
            }

            return $this->executeRemoteAction($node, $toolDef, $params, $context);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('CrossNodeToolResolver: remote execution failed', [
                'node' => $nodeSlug,
                'tool' => $toolDef['description'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return 'Error: ' . $e->getMessage();
        }
    }

    // ──────────────────────────────────────────────
    //  Execution strategies
    // ──────────────────────────────────────────────

    protected function executeRemoteSearch(AINode $node, array $toolDef, array $params, UnifiedActionContext $context): string
    {
        $query = $params['query'] ?? '';
        $limit = (int) ($params['limit'] ?? 10);
        $collection = $toolDef['_collection'] ?? $toolDef['model'] ?? '';

        $result = $this->forwarder->forwardSearch(
            $node,
            $query,
            [$collection],
            $limit,
            ['session_id' => $context->sessionId],
            $context->userId
        );

        if ($result['success']) {
            $count = $result['count'] ?? count($result['results'] ?? []);
            $data = [
                'success' => true,
                'node' => $result['node'],
                'count' => $count,
                'results' => array_slice($result['results'] ?? [], 0, $limit),
            ];
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return 'Error: Search failed on node ' . $node->slug . ' — ' . ($result['error'] ?? 'unknown error');
    }

    protected function executeRemoteAction(AINode $node, array $toolDef, array $params, UnifiedActionContext $context): string
    {
        $actionName = '';
        // Find the tool name from the registry key
        foreach ($params as $key => $value) {
            if ($key === 'action_name') {
                $actionName = $value;
                break;
            }
        }

        // Use the tool description as action type if no explicit action_name
        if (!$actionName) {
            $actionName = $toolDef['model'] ?? 'remote_action';
        }

        $result = $this->forwarder->forwardAction($node, $actionName, array_merge($params, [
            'session_id' => $context->sessionId,
            'user_id' => $context->userId,
        ]));

        if ($result['success']) {
            return json_encode([
                'success' => true,
                'node' => $result['node'],
                'data' => $result['data'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return 'Error: Action failed on node ' . $node->slug . ' — ' . ($result['error'] ?? 'unknown error');
    }

    // ──────────────────────────────────────────────
    //  Utility
    // ──────────────────────────────────────────────

    /**
     * Check if a tool definition is remote.
     */
    public function isRemote(array $toolDef): bool
    {
        return ($toolDef['source'] ?? 'local') === 'remote';
    }

    /**
     * Find which node owns a given collection name.
     *
     * Useful for cross-node dependency resolution: if the agent needs
     * data from collection X before calling a local tool, this tells
     * it which node to query.
     */
    public function findNodeForCollection(string $collectionName): ?AINode
    {
        return $this->registry->findNodeForCollection($collectionName);
    }
}
