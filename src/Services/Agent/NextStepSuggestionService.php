<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Contracts\AutonomousModelConfig;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Generates contextual "what you can do next" suggestions after each response.
 *
 * Two modes:
 *  - template (default, zero cost): builds suggestions from available tools,
 *    collectors, and the current context state.
 *  - ai: uses a small LLM call to generate natural-language suggestions.
 *
 * Suggestions are returned as structured arrays that the frontend can render
 * as clickable chips/buttons or plain text hints.
 *
 * Each suggestion contains:
 *  - label:  Human-readable text ("Create a new invoice")
 *  - action: The orchestrator action type (use_tool, start_collector, search_rag, route_to_node)
 *  - resource: The resource name (tool name, collector name, node slug)
 *  - prompt: A suggested user message that would trigger this action
 */
class NextStepSuggestionService
{
    public function __construct(
        protected array $settings = []
    ) {
    }

    // ──────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────

    /**
     * Generate suggested next actions based on the current context.
     *
     * @param UnifiedActionContext $context     Current session context
     * @param string               $lastAction  The action that was just executed (search_rag, use_tool, etc.)
     * @param string|null          $lastResource The resource that was used (tool name, collector name, etc.)
     * @param array                $resources   Available resources (tools, collectors, nodes)
     * @param array                $options     Extra options
     * @return array<int, array{label: string, action: string, resource: string, prompt: string}>
     */
    public function suggest(
        UnifiedActionContext $context,
        string $lastAction,
        ?string $lastResource = null,
        array $resources = [],
        array $options = []
    ): array {
        $suggestions = [];

        // 1. Context-aware suggestions based on what just happened
        $suggestions = array_merge($suggestions, $this->suggestFromLastAction($lastAction, $lastResource, $context));

        // 2. Entity-aware suggestions (if we have a selected entity or entity list)
        $suggestions = array_merge($suggestions, $this->suggestFromEntityContext($context, $resources));

        // 3. Available tool suggestions (tools the user hasn't used yet)
        $suggestions = array_merge($suggestions, $this->suggestFromAvailableTools($resources, $lastResource));

        // 4. Node-aware suggestions (if remote nodes are available)
        $suggestions = array_merge($suggestions, $this->suggestFromNodes($resources, $context));

        // Deduplicate and limit
        $suggestions = $this->deduplicateAndLimit($suggestions, $this->maxSuggestions());

        Log::channel('ai-engine')->debug('NextStepSuggestionService generated suggestions', [
            'count' => count($suggestions),
            'last_action' => $lastAction,
            'last_resource' => $lastResource,
        ]);

        return $suggestions;
    }

    // ──────────────────────────────────────────────
    //  Context-based suggestions
    // ──────────────────────────────────────────────

    protected function suggestFromLastAction(string $lastAction, ?string $lastResource, UnifiedActionContext $context): array
    {
        $suggestions = [];

        switch ($lastAction) {
            case 'search_rag':
                // After a search, suggest drilling down or taking action
                $entityList = $context->metadata['last_entity_list'] ?? [];
                if (!empty($entityList)) {
                    $suggestions[] = $this->makeSuggestion(
                        'Select an item from the list (e.g. "1" or "the first one")',
                        'search_rag',
                        $lastResource,
                        '1'
                    );
                }
                $suggestions[] = $this->makeSuggestion(
                    'Refine your search with more specific criteria',
                    'search_rag',
                    $lastResource,
                    'Show me only the recent ones'
                );
                break;

            case 'use_tool':
                // After a tool execution, suggest related actions
                if ($lastResource && str_starts_with($lastResource, 'create_')) {
                    $entityType = str_replace('create_', '', $lastResource);
                    $suggestions[] = $this->makeSuggestion(
                        "List all {$entityType}s",
                        'search_rag',
                        $entityType,
                        "list {$entityType}s"
                    );
                    $suggestions[] = $this->makeSuggestion(
                        "Create another {$entityType}",
                        'use_tool',
                        $lastResource,
                        "create another {$entityType}"
                    );
                }
                if ($lastResource && str_starts_with($lastResource, 'update_')) {
                    $entityType = str_replace('update_', '', $lastResource);
                    $suggestions[] = $this->makeSuggestion(
                        "View the updated {$entityType}",
                        'search_rag',
                        $entityType,
                        "show me the {$entityType}"
                    );
                }
                break;

            case 'start_collector':
                // After a collector completes, suggest viewing the result
                if ($lastResource) {
                    $suggestions[] = $this->makeSuggestion(
                        "View the created {$lastResource}",
                        'search_rag',
                        $lastResource,
                        "show me the {$lastResource}"
                    );
                }
                break;

            case 'route_to_node':
                // After routing to a node, suggest follow-up
                $nodeInfo = $context->get('routed_to_node');
                if ($nodeInfo) {
                    $suggestions[] = $this->makeSuggestion(
                        'Ask a follow-up question about the results',
                        'route_to_node',
                        $nodeInfo['node_slug'] ?? null,
                        'Tell me more about this'
                    );
                }
                break;
        }

        return $suggestions;
    }

    // ──────────────────────────────────────────────
    //  Entity-aware suggestions
    // ──────────────────────────────────────────────

    protected function suggestFromEntityContext(UnifiedActionContext $context, array $resources): array
    {
        $suggestions = [];
        $selectedEntity = $context->metadata['selected_entity_context'] ?? null;

        if ($selectedEntity && !empty($selectedEntity['entity_type'])) {
            $entityType = strtolower($selectedEntity['entity_type']);
            $tools = $resources['tools'] ?? [];

            // Find tools that match this entity type
            foreach ($tools as $tool) {
                $toolName = $tool['name'] ?? '';
                $model = strtolower($tool['model'] ?? '');

                if ($model === $entityType || str_contains($toolName, $entityType)) {
                    // Suggest update/delete tools for the selected entity
                    if (str_starts_with($toolName, 'update_')) {
                        $suggestions[] = $this->makeSuggestion(
                            "Update this {$entityType}",
                            'use_tool',
                            $toolName,
                            "update this {$entityType}"
                        );
                    }
                    if (str_starts_with($toolName, 'delete_')) {
                        $suggestions[] = $this->makeSuggestion(
                            "Delete this {$entityType}",
                            'use_tool',
                            $toolName,
                            "delete this {$entityType}"
                        );
                    }
                }
            }
        }

        return $suggestions;
    }

    // ──────────────────────────────────────────────
    //  Tool-based suggestions
    // ──────────────────────────────────────────────

    protected function suggestFromAvailableTools(array $resources, ?string $lastResource): array
    {
        $suggestions = [];
        $tools = $resources['tools'] ?? [];

        // Suggest create tools that weren't just used
        foreach ($tools as $tool) {
            $toolName = $tool['name'] ?? '';
            if ($toolName === $lastResource) {
                continue; // Skip the tool we just used
            }

            if (str_starts_with($toolName, 'create_')) {
                $entityType = str_replace('create_', '', $toolName);
                $description = $tool['description'] ?? "Create a new {$entityType}";
                $suggestions[] = $this->makeSuggestion(
                    $description,
                    'use_tool',
                    $toolName,
                    "create a new {$entityType}"
                );
            }
        }

        // Suggest collectors
        $collectors = $resources['collectors'] ?? [];
        foreach ($collectors as $collector) {
            $name = $collector['name'] ?? '';
            if ($name === $lastResource) {
                continue;
            }
            $goal = $collector['goal'] ?? $collector['description'] ?? '';
            if ($goal !== '') {
                $suggestions[] = $this->makeSuggestion(
                    $goal,
                    'start_collector',
                    $name,
                    $goal
                );
            }
        }

        return $suggestions;
    }

    // ──────────────────────────────────────────────
    //  Node-based suggestions
    // ──────────────────────────────────────────────

    protected function suggestFromNodes(array $resources, UnifiedActionContext $context): array
    {
        $suggestions = [];
        $nodes = $resources['nodes'] ?? [];
        $currentNodeSlug = $context->get('routed_to_node')['node_slug'] ?? null;

        foreach ($nodes as $node) {
            $slug = $node['slug'] ?? '';
            if ($slug === $currentNodeSlug || $slug === '') {
                continue;
            }

            $description = $node['description'] ?? '';
            $domains = $node['domains'] ?? [];
            $domainHint = !empty($domains) ? implode(', ', array_slice($domains, 0, 2)) : $slug;

            $suggestions[] = $this->makeSuggestion(
                "Ask about {$domainHint}",
                'route_to_node',
                $slug,
                "show me {$domainHint} data"
            );
        }

        return $suggestions;
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    protected function makeSuggestion(string $label, string $action, ?string $resource, string $prompt): array
    {
        return [
            'label' => $label,
            'action' => $action,
            'resource' => $resource ?? '',
            'prompt' => $prompt,
        ];
    }

    protected function deduplicateAndLimit(array $suggestions, int $max): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $s) {
            $key = $s['action'] . ':' . $s['resource'] . ':' . strtolower($s['label']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $s;
        }

        return array_slice($unique, 0, $max);
    }

    protected function maxSuggestions(): int
    {
        return (int) ($this->settings['max_suggestions'] ?? config('ai-agent.next_step.max_suggestions', 4));
    }
}
