<?php

namespace LaravelAIEngine\Services\Node;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AINode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generates and caches AI-friendly routing digests for nodes.
 *
 * Instead of dumping raw metadata (collections, domains, workflows, keywords)
 * into the orchestrator prompt on every message, this service pre-computes a
 * compact routing summary that the AI can parse quickly with fewer tokens.
 *
 * Two modes (controlled by `ai-engine.nodes.digest_mode`):
 *
 *  - **template** (default, zero cost): deterministic template that compresses
 *    node metadata into a routing-friendly string.
 *
 *  - **ai**: uses a small LLM call to generate a natural-language routing
 *    summary. More token-efficient in the prompt but costs one LLM call
 *    per node per metadata change.
 *
 * Digests are cached and only regenerated when node metadata changes
 * (triggered after health ping sync or manual refresh).
 */
class NodeRoutingDigestService
{
    protected const CACHE_PREFIX = 'node_routing_digest:';
    protected const FULL_DIGEST_KEY = 'node_routing_digest:full';

    public function __construct(
        protected NodeRegistryService $registry
    ) {
    }

    // ──────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────

    /**
     * Get the full routing digest for all nodes (remote + local).
     *
     * This is the single string injected into the orchestrator prompt.
     * Cached until any node metadata changes.
     */
    public function getFullDigest(?array $localNodeMeta = null): string
    {
        $ttl = $this->cacheTtlMinutes();

        return Cache::remember(self::FULL_DIGEST_KEY, now()->addMinutes($ttl), function () use ($localNodeMeta) {
            return $this->buildFullDigest($localNodeMeta);
        });
    }

    /**
     * Get the routing digest for a single node.
     */
    public function getNodeDigest(AINode $node): string
    {
        $cacheKey = self::CACHE_PREFIX . $node->slug;
        $ttl = $this->cacheTtlMinutes();

        return Cache::remember($cacheKey, now()->addMinutes($ttl), function () use ($node) {
            return $this->buildNodeDigest($node);
        });
    }

    /**
     * Regenerate digest for a specific node (call after metadata sync).
     */
    public function refreshNodeDigest(AINode $node): string
    {
        $digest = $this->buildNodeDigest($node);

        Cache::put(
            self::CACHE_PREFIX . $node->slug,
            $digest,
            now()->addMinutes($this->cacheTtlMinutes())
        );

        // Invalidate the full digest so it gets rebuilt with the new node digest
        Cache::forget(self::FULL_DIGEST_KEY);

        Log::channel('ai-engine')->debug('Node routing digest refreshed', [
            'node' => $node->slug,
            'digest_length' => strlen($digest),
        ]);

        return $digest;
    }

    /**
     * Invalidate all cached digests (call after bulk changes).
     */
    public function invalidateAll(): void
    {
        Cache::forget(self::FULL_DIGEST_KEY);

        foreach ($this->registry->getActiveNodes() as $node) {
            Cache::forget(self::CACHE_PREFIX . $node->slug);
        }
    }

    // ──────────────────────────────────────────────
    //  Full digest builder
    // ──────────────────────────────────────────────

    protected function buildFullDigest(?array $localNodeMeta = null): string
    {
        $lines = [];

        // Remote nodes
        $remoteNodes = $this->registry->getActiveNodes()->filter(
            fn (AINode $n) => $n->type === 'child'
        );

        if ($remoteNodes->isNotEmpty()) {
            $lines[] = 'REMOTE NODES:';
            foreach ($remoteNodes as $node) {
                $lines[] = $this->getNodeDigest($node);
            }
        }

        // Local node
        if ($localNodeMeta !== null) {
            $lines[] = '';
            $lines[] = 'LOCAL NODE:';
            $lines[] = $this->buildLocalDigest($localNodeMeta);
        }

        if (empty($lines)) {
            return '(No nodes available)';
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    //  Per-node digest builders
    // ──────────────────────────────────────────────

    protected function buildNodeDigest(AINode $node): string
    {
        $mode = $this->digestMode();

        if ($mode === 'ai') {
            $aiDigest = $this->buildAIDigest($node);
            if ($aiDigest !== null) {
                return $aiDigest;
            }
            // Fall through to template if AI fails
        }

        return $this->buildTemplateDigest($node);
    }

    /**
     * Template-based digest — zero cost, deterministic.
     *
     * Produces a compact, routing-friendly line like:
     *   "- invoicing (invoicing-node): manages invoices, bills, payments.
     *    Can: create invoices, search transactions. Domains: finance, accounting."
     */
    protected function buildTemplateDigest(AINode $node): string
    {
        $slug = $node->slug;
        $name = $node->name ?: $slug;

        // What it manages (from collections)
        $collections = $this->extractCollectionNames($node);
        $manages = !empty($collections)
            ? 'manages ' . $this->joinWords($collections)
            : 'general purpose';

        // What it can do (from autonomous_collectors + capabilities)
        $actions = $this->extractActions($node);
        $canDo = !empty($actions)
            ? 'Can: ' . $this->joinWords($actions)
            : '';

        // Domains (compact)
        $domains = $node->domains ?? [];
        $domainStr = !empty($domains)
            ? 'Domains: ' . implode(', ', array_slice($domains, 0, 5))
            : '';

        // Build the line
        $parts = ["- {$name} ({$slug}): {$manages}."];
        if ($canDo) {
            $parts[] = $canDo . '.';
        }
        if ($domainStr) {
            $parts[] = $domainStr . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * AI-generated digest — uses a small LLM call to produce a natural-language
     * routing summary. Only called when digest_mode=ai.
     */
    protected function buildAIDigest(AINode $node): ?string
    {
        try {
            $aiService = app(\LaravelAIEngine\Services\AIEngineService::class);

            $metadata = json_encode([
                'slug' => $node->slug,
                'name' => $node->name,
                'description' => $node->description,
                'collections' => $this->extractCollectionNames($node),
                'domains' => $node->domains ?? [],
                'capabilities' => $node->capabilities ?? [],
                'autonomous_collectors' => array_map(
                    fn ($c) => $c['goal'] ?? $c['name'] ?? '',
                    $node->autonomous_collectors ?? []
                ),
                'workflows' => array_map('class_basename', $node->workflows ?? []),
            ], JSON_PRETTY_PRINT);

            $prompt = <<<PROMPT
You are writing a routing guide for an AI orchestrator. Given this node's metadata, write a single concise line (max 80 words) that tells the orchestrator WHEN to route requests to this node.

Focus on:
- What data/entities this node owns
- What actions users can perform (create, search, list, etc.)
- What business domain it covers

Node metadata:
{$metadata}

Write ONLY the routing line, starting with "- {$node->name} ({$node->slug}):". No preamble.
PROMPT;

            $request = new AIRequest(
                prompt: $prompt,
                engine: EngineEnum::from(config('ai-engine.default', 'openai')),
                model: EntityEnum::from(config('ai-engine.nodes.digest_model', 'gpt-4o-mini')),
                maxTokens: 150,
                temperature: 0.3
            );

            $response = $aiService->generate($request);
            $digest = trim($response->getContent());

            if (strlen($digest) > 20) {
                Log::channel('ai-engine')->info('AI routing digest generated', [
                    'node' => $node->slug,
                    'digest' => $digest,
                ]);
                return $digest;
            }
        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('AI routing digest generation failed, falling back to template', [
                'node' => $node->slug,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Build digest for the local node from pre-discovered metadata.
     */
    protected function buildLocalDigest(array $meta): string
    {
        $slug = $meta['slug'] ?? 'local';
        $description = $meta['description'] ?? '';

        $collections = [];
        foreach ($meta['collections'] ?? [] as $c) {
            $collections[] = is_array($c) ? ($c['name'] ?? '') : strtolower(class_basename($c));
        }
        $collections = array_filter($collections);

        $manages = !empty($collections)
            ? 'manages ' . $this->joinWords($collections)
            : ($description ?: 'local data');

        $domains = $meta['domains'] ?? [];
        $domainStr = !empty($domains)
            ? 'Domains: ' . implode(', ', array_slice($domains, 0, 5))
            : '';

        $parts = ["- Local ({$slug}): {$manages}."];
        if ($domainStr) {
            $parts[] = $domainStr . '.';
        }

        return implode(' ', $parts);
    }

    // ──────────────────────────────────────────────
    //  Metadata extraction helpers
    // ──────────────────────────────────────────────

    protected function extractCollectionNames(AINode $node): array
    {
        $names = [];
        foreach ($node->collections ?? [] as $collection) {
            if (is_array($collection)) {
                $names[] = $collection['name'] ?? '';
            } else {
                $names[] = strtolower(class_basename($collection));
            }
        }
        return array_values(array_filter($names));
    }

    protected function extractActions(AINode $node): array
    {
        $actions = [];

        // From autonomous collectors
        foreach ($node->autonomous_collectors ?? [] as $collector) {
            $goal = $collector['goal'] ?? '';
            if ($goal !== '') {
                // Compress: "Create a new sales invoice" → "create invoices"
                if (preg_match('/^(create|update|delete|manage|search|list)\b/i', $goal, $m)) {
                    $actions[] = strtolower($goal);
                } else {
                    $actions[] = $goal;
                }
            }
        }

        // From capabilities
        $caps = $node->capabilities ?? [];
        if (in_array('search', $caps) || in_array('rag', $caps)) {
            $collections = $this->extractCollectionNames($node);
            if (!empty($collections)) {
                $actions[] = 'search ' . $this->joinWords(array_slice($collections, 0, 3));
            }
        }

        return array_values(array_unique(array_slice($actions, 0, 5)));
    }

    protected function joinWords(array $words, int $max = 5): string
    {
        $words = array_slice($words, 0, $max);
        if (count($words) <= 2) {
            return implode(' and ', $words);
        }
        $last = array_pop($words);
        return implode(', ', $words) . ', and ' . $last;
    }

    // ──────────────────────────────────────────────
    //  Config
    // ──────────────────────────────────────────────

    protected function digestMode(): string
    {
        return (string) config('ai-engine.nodes.digest_mode', 'template');
    }

    protected function cacheTtlMinutes(): int
    {
        return max(1, (int) config('ai-engine.nodes.digest_cache_ttl_minutes', 60));
    }
}
