<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Log;

/**
 * Node Router Service — Pure Routing Decision Layer
 *
 * Decides WHICH node should handle a request. Does NOT perform HTTP
 * forwarding — that responsibility belongs to NodeForwarder.
 *
 * Decision strategies (evaluated in order):
 *  1. Collection ownership — if collections are specified, find the owning node
 *  2. AI intent analysis  — LLM picks the best node from descriptions/capabilities
 *  3. Keyword scoring      — fallback heuristic using NodeNameMatcher
 *
 * HTTP forwarding methods (forwardChat, forwardSearch) are kept as thin
 * delegates to NodeForwarder for backward compatibility.
 */
class NodeRouterService
{
    public function __construct(
        protected NodeRegistryService $registry,
        protected NodeForwarder $forwarder
    ) {
    }

    /**
     * Route a query to the appropriate node
     *
     * @param string $query User's query
     * @param array $collections Collections to search (from AI analysis)
     * @param array $options Additional options
     * @return array ['node' => AINode|null, 'is_local' => bool, 'reason' => string]
     */
    public function route(string $query, array $collections = [], array $options = []): array
    {
        // If collections are specified, find node that owns them
        if (!empty($collections)) {
            return $this->routeByCollections($collections);
        }

        // Otherwise, analyze query to determine routing
        return $this->routeByQueryAnalysis($query, $options);
    }

    /**
     * Route based on specified collections
     */
    protected function routeByCollections(array $collections): array
    {
        foreach ($collections as $collection) {
            $node = $this->registry->findNodeForCollection($collection);

            if ($node && $node->type === 'child') {
                if (!$this->forwarder->isAvailable($node)) {
                    Log::channel('ai-engine')->warning('Node for collection is unavailable, falling back to local', [
                        'collection' => $collection,
                        'node' => $node->slug,
                    ]);
                    continue;
                }

                return [
                    'node' => $node,
                    'is_local' => false,
                    'reason' => "Collection {$collection} is owned by node {$node->name}",
                    'collections' => $collections,
                ];
            }
        }

        return [
            'node' => null,
            'is_local' => true,
            'reason' => 'Collections are local or no remote node owns them',
            'collections' => $collections,
        ];
    }

    /**
     * Route based on AI analysis of query intent vs node descriptions/goals
     */
    protected function routeByQueryAnalysis(string $query, array $options = []): array
    {
        // Get all active child nodes
        $nodes = AINode::active()->healthy()->child()->get();

        if ($nodes->isEmpty()) {
            return [
                'node' => null,
                'is_local' => true,
                'reason' => 'No remote nodes available',
                'collections' => [],
            ];
        }

        // Use AI to determine the best node based on descriptions and goals
        $selectedNode = $this->aiSelectNode($query, $nodes);

        if (!$selectedNode) {
            return [
                'node' => null,
                'is_local' => true,
                'reason' => 'AI determined query should be handled locally',
                'collections' => [],
            ];
        }

        // Check if node is available
        if (!$this->forwarder->isAvailable($selectedNode['node'])) {
            return [
                'node' => null,
                'is_local' => true,
                'reason' => "Selected node {$selectedNode['node']->name} is unavailable",
                'collections' => [],
            ];
        }

        return [
            'node' => $selectedNode['node'],
            'is_local' => false,
            'reason' => $selectedNode['reason'],
            'collections' => $selectedNode['node']->collections ?? [],
        ];
    }

    /**
     * Use AI to select the best node for a query based on node descriptions and goals
     */
    protected function aiSelectNode(string $query, $nodes): ?array
    {
        // Build node descriptions for AI
        $nodeDescriptions = [];
        foreach ($nodes as $node) {
            $nodeDescriptions[] = [
                'id' => $node->id,
                'name' => $node->name,
                'slug' => $node->slug,
                'description' => $node->description ?? '',
                'capabilities' => $node->capabilities ?? [],
                'domains' => $node->domains ?? [],
                'data_types' => $node->data_types ?? [],
                'workflows' => array_map(fn($w) => class_basename($w), $node->workflows ?? []),
                'autonomous_collectors' => $node->autonomous_collectors ?? [],
            ];
        }

        // If no nodes have descriptions, fall back to keyword matching
        $hasDescriptions = collect($nodeDescriptions)->filter(fn($n) => !empty($n['description']))->isNotEmpty();
        if (!$hasDescriptions) {
            Log::channel('ai-engine')->debug('No node descriptions available, falling back to keyword matching');
            return $this->keywordBasedSelection($query, $nodes);
        }

        $prompt = $this->buildNodeSelectionPrompt($query, $nodeDescriptions);

        try {
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);

            // Create AI request for node selection
            $request = new AIRequest(
                prompt:      $prompt,
                engine:      EngineEnum::from(config('ai-engine.default', 'openai')),
                model:       EntityEnum::from(config('ai-engine.nodes.routing_model', 'gpt-4o-mini')),
                maxTokens:   200,
                temperature: 0.1
            );

            $response = $aiService->generate($request);

            $result = $this->parseNodeSelectionResponse($response->getContent(), $nodes);

            Log::channel('ai-engine')->info('AI node selection result', [
                'query' => substr($query, 0, 100),
                'selected_node' => $result ? $result['node']->slug : 'local',
                'reason' => $result['reason'] ?? 'handled locally',
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI node selection failed, falling back to keyword matching', [
                'error' => $e->getMessage(),
            ]);
            return $this->keywordBasedSelection($query, $nodes);
        }
    }

    /**
     * Build the prompt for AI node selection
     */
    protected function buildNodeSelectionPrompt(string $query, array $nodeDescriptions): string
    {
        $nodesJson = json_encode($nodeDescriptions, JSON_PRETTY_PRINT);

        return <<<PROMPT
You are a routing assistant. Analyze the user's query and determine which specialized node should handle it.

Available nodes:
{$nodesJson}

User query: "{$query}"

Instructions:
1. Analyze the user's intent from their query
2. Match the intent against each node's description, capabilities, domains, workflows, and autonomous_collectors
3. Workflows represent the node's goals and capabilities:
   - Workflow names contain the entity they manage (e.g., "InvoiceWorkflow" handles invoices)
   - Common patterns: "Create*", "Declarative*", "Manage*", "Simple*" all indicate entity management
4. Autonomous collectors are AI-driven data collection goals:
   - Each collector has a "goal" describing what it creates (e.g., "Create a sales invoice")
   - If user wants to create something matching a collector's goal, route to that node
5. If user wants to "create/add/new/make X", look for:
   - A workflow containing that entity name, OR
   - An autonomous_collector with a matching goal
6. Select the BEST matching node, or respond with "LOCAL" if no node is appropriate

Respond in this exact format:
NODE: <node_slug or LOCAL>
REASON: <brief explanation>
PROMPT;
    }

    /**
     * Parse AI response and return selected node
     */
    protected function parseNodeSelectionResponse(string $response, $nodes): ?array
    {
        // Extract node slug from response
        if (preg_match('/NODE:\s*(\S+)/i', $response, $matches)) {
            $selectedSlug = strtolower(trim($matches[1]));

            if ($selectedSlug === 'local' || $selectedSlug === 'none') {
                return null;
            }

            // Find the node
            $node = $nodes->first(fn($n) => strtolower($n->slug) === $selectedSlug);

            if ($node) {
                $reason = 'AI selected based on intent analysis';
                if (preg_match('/REASON:\s*(.+)/i', $response, $reasonMatch)) {
                    $reason = trim($reasonMatch[1]);
                }

                return [
                    'node' => $node,
                    'reason' => $reason,
                ];
            }
        }

        return null;
    }

    /**
     * Fallback: keyword-based node selection using NodeNameMatcher.
     */
    protected function keywordBasedSelection(string $query, $nodes): ?array
    {
        $minScore = (int) config('ai-engine.nodes.routing.min_keyword_score', 10);
        $scores = [];

        foreach ($nodes as $node) {
            $score = $this->scoreNodeForQuery($node, $query);

            if ($score >= $minScore) {
                $scores[$node->id] = [
                    'node' => $node,
                    'score' => $score,
                ];
            }
        }

        if (empty($scores)) {
            return null;
        }

        uasort($scores, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = reset($scores);

        return [
            'node' => $best['node'],
            'reason' => "Keyword match (score: {$best['score']})",
        ];
    }

    /**
     * Score how well a node matches a query using NodeNameMatcher.
     *
     * Weights: collection match (15) > keyword (10) > data_type (8) > domain (5)
     */
    public function scoreNodeForQuery(AINode $node, string $query): int
    {
        $queryLower = strtolower($query);
        $score = 0;

        // Collection names (strongest signal)
        foreach ($node->collections ?? [] as $collection) {
            $name = is_array($collection)
                ? ($collection['name'] ?? '')
                : strtolower(class_basename($collection));

            if (NodeNameMatcher::contains($queryLower, strtolower($name))) {
                $score += 15;
            }
        }

        // Keywords
        foreach ($node->keywords ?? [] as $keyword) {
            if (NodeNameMatcher::contains($queryLower, strtolower((string) $keyword))) {
                $score += 10;
            }
        }

        // Data types
        foreach ($node->data_types ?? [] as $dataType) {
            if (NodeNameMatcher::contains($queryLower, strtolower((string) $dataType))) {
                $score += 8;
            }
        }

        // Domains
        foreach ($node->domains ?? [] as $domain) {
            if (NodeNameMatcher::contains($queryLower, strtolower((string) $domain))) {
                $score += 5;
            }
        }

        return $score;
    }

    // ──────────────────────────────────────────────
    //  Backward-compatible delegates to NodeForwarder
    // ──────────────────────────────────────────────

    /**
     * @deprecated Use NodeForwarder::forwardSearch() directly.
     */
    public function forwardSearch(
        AINode $node,
        string $query,
        array $collections,
        int $limit,
        array $options = [],
        $userId = null
    ): array {
        return $this->forwarder->forwardSearch($node, $query, $collections, $limit, $options, $userId);
    }

    /**
     * @deprecated Use NodeForwarder::forwardChat() directly.
     */
    public function forwardChat(
        AINode $node,
        string $message,
        string $sessionId,
        array $options = [],
        $userId = null
    ): array {
        return $this->forwarder->forwardChat($node, $message, $sessionId, $options, $userId);
    }

    // ──────────────────────────────────────────────
    //  Debugging / explain
    // ──────────────────────────────────────────────

    /**
     * Get routing decision with per-node scores for logging/debugging.
     */
    public function explainRouting(string $query, array $collections = []): array
    {
        $routing = $this->route($query, $collections);

        $nodes = AINode::active()->child()->get();
        $nodeScores = [];

        foreach ($nodes as $node) {
            $nodeScores[$node->slug] = [
                'name' => $node->name,
                'score' => $this->scoreNodeForQuery($node, $query),
                'available' => $this->forwarder->isAvailable($node),
                'collections' => $node->collections ?? [],
                'keywords' => $node->keywords ?? [],
            ];
        }

        return [
            'query' => $query,
            'collections_requested' => $collections,
            'routing_decision' => $routing,
            'node_scores' => $nodeScores,
        ];
    }
}
