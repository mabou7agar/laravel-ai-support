<?php

namespace LaravelAIEngine\Services\RAG;

use LaravelAIEngine\Models\AIPromptPolicyVersion;

class AutonomousRAGDecisionPromptService
{
    protected string $defaultTemplatePath;
    protected array $lastPolicySelection = [];

    public function __construct(
        protected AutonomousRAGPolicy $policy,
        protected AutonomousRAGDecisionFeedbackService $feedback,
        protected ?AutonomousRAGPromptPolicyService $promptPolicyService = null
    ) {
        $this->defaultTemplatePath = dirname(__DIR__, 3) . '/resources/prompts/rag/decision_prompt.txt';
        $this->promptPolicyService = $this->promptPolicyService ?? new AutonomousRAGPromptPolicyService($policy);
    }

    public function build(string $message, array $context): string
    {
        return $this->buildWithMetadata($message, $context)['prompt'];
    }

    public function buildWithMetadata(string $message, array $context): array
    {
        $defaultLimit = $this->policy->itemsPerPage();
        $businessContext = $this->policy->decisionBusinessContext();
        $adaptiveHints = $this->feedback->adaptiveHints($businessContext);
        $policySelection = $this->resolvePolicySelection($context);
        $template = $policySelection['template'] ?? $this->loadTemplateFromFiles();

        $prompt = $this->renderTemplate($template, [
            '{{USER_REQUEST}}' => $message,
            '{{CONVERSATION}}' => (string) ($context['conversation'] ?? ''),
            '{{VISIBLE_LIST_CONTEXT}}' => $this->renderVisibleListContext($context),
            '{{SELECTED_ENTITY_CONTEXT}}' => $this->renderSelectedEntityContext($context),
            '{{MODELS_JSON}}' => json_encode($this->normalizeModels($context), JSON_PRETTY_PRINT),
            '{{NODES_JSON}}' => $this->renderNodes($context),
            '{{BUSINESS_CONTEXT}}' => $this->renderBusinessContext($businessContext),
            '{{ADAPTIVE_HINTS}}' => $this->renderAdaptiveHints($adaptiveHints),
            '{{POLICY_CONTEXT}}' => $this->renderPolicyContext($policySelection),
            '{{DEFAULT_LIMIT}}' => (string) $defaultLimit,
        ]);

        return [
            'prompt' => $prompt,
            'policy' => $policySelection['metadata'] ?? null,
        ];
    }

    public function getLastPolicySelection(): array
    {
        return $this->lastPolicySelection;
    }

    protected function renderTemplate(string $template, array $variables): string
    {
        return strtr($template, $variables);
    }

    protected function loadTemplateFromFiles(): string
    {
        $configured = $this->policy->decisionPromptTemplatePath();
        $candidates = array_values(array_filter([$configured, $this->defaultTemplatePath]));

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                $content = file_get_contents($path);
                if (is_string($content) && trim($content) !== '') {
                    return $content;
                }
            }
        }

        return $this->fallbackTemplate();
    }

    protected function resolvePolicySelection(array $context): array
    {
        $template = $this->loadTemplateFromFiles();
        $selection = [
            'template' => $template,
            'metadata' => null,
            'selection' => 'template_file',
        ];

        if (!$this->promptPolicyService || !$this->promptPolicyService->storeAvailable()) {
            $this->lastPolicySelection = $selection;

            return $selection;
        }

        $resolved = $this->promptPolicyService->resolveForRuntime($context);

        if (!$resolved['selected']) {
            $this->promptPolicyService->ensureActiveDefault($template);
            $resolved = $this->promptPolicyService->resolveForRuntime($context);
        }

        $selectedPolicy = $resolved['selected'];
        if ($selectedPolicy instanceof AIPromptPolicyVersion && trim($selectedPolicy->template) !== '') {
            $selection['template'] = $selectedPolicy->template;
            $selection['metadata'] = [
                'id' => $selectedPolicy->id,
                'policy_key' => $selectedPolicy->policy_key,
                'version' => $selectedPolicy->version,
                'status' => $selectedPolicy->status,
                'scope_key' => $selectedPolicy->scope_key,
                'selection' => $resolved['selection'] ?? $selectedPolicy->status,
            ];
            $selection['selection'] = $selection['metadata']['selection'];
        }

        $this->lastPolicySelection = $selection;

        return $selection;
    }

    protected function normalizeModels(array $context): array
    {
        $modelLimit = $this->promptLimit('models', 12);
        $fieldLimit = $this->promptLimit('model_fields', 12);
        $toolLimit = $this->promptLimit('model_tools', 8);

        return collect($context['models'] ?? [])->take($modelLimit)->map(function (array $model) use ($fieldLimit, $toolLimit) {
            $schemaFields = !empty($model['schema']) ? array_keys((array) $model['schema']) : [];
            $toolNames = !empty($model['tools']) ? array_keys((array) $model['tools']) : [];

            return [
                'name' => $model['name'] ?? 'unknown',
                'description' => $model['description'] ?? null,
                'table' => $model['table'] ?? null,
                'capabilities' => $model['capabilities'] ?? [],
                'key_fields' => array_slice($schemaFields, 0, $fieldLimit),
                'tools' => array_slice($toolNames, 0, $toolLimit),
                'location' => $model['location'] ?? 'local',
            ];
        })->values()->all();
    }

    protected function renderVisibleListContext(array $context): string
    {
        $last = $context['last_entity_list'] ?? null;
        if (!is_array($last) || empty($last['entity_data'])) {
            return '(none)';
        }

        $entityType = (string) ($last['entity_type'] ?? 'item');
        $entityIds = (array) ($last['entity_ids'] ?? []);
        $startPosition = (int) ($last['start_position'] ?? 1);
        $endPosition = (int) ($last['end_position'] ?? count($entityIds));

        $lines = [
            "CURRENTLY VISIBLE {$entityType}s (positions {$startPosition}-{$endPosition})",
        ];

        if (!empty($entityIds)) {
            $lines[] = 'ENTITY IDS: ' . json_encode($entityIds);
        }

        return implode("\n", $lines);
    }

    protected function renderSelectedEntityContext(array $context): string
    {
        $selected = $context['selected_entity'] ?? null;
        if (!is_array($selected) || empty($selected)) {
            return '(none)';
        }

        return json_encode($selected, JSON_PRETTY_PRINT);
    }

    protected function renderNodes(array $context): string
    {
        $nodes = $this->normalizeNodes($context['nodes'] ?? []);
        if (empty($nodes)) {
            return '[]';
        }

        return json_encode($nodes, JSON_PRETTY_PRINT);
    }

    protected function normalizeNodes(array $nodes): array
    {
        $nodeLimit = $this->promptLimit('nodes', 8);
        $collectionLimit = $this->promptLimit('node_collections', 10);

        return collect($nodes)->take($nodeLimit)->map(function (array $node) use ($collectionLimit) {
            $collections = collect((array) ($node['collections'] ?? []))
                ->map(function ($collection) {
                    if (is_array($collection)) {
                        return (string) ($collection['name'] ?? '');
                    }

                    return strtolower((string) class_basename((string) $collection));
                })
                ->filter(fn (string $name) => $name !== '')
                ->take($collectionLimit)
                ->values()
                ->all();

            return [
                'slug' => (string) ($node['slug'] ?? ''),
                'name' => (string) ($node['name'] ?? ''),
                'description' => (string) ($node['description'] ?? ''),
                'collections' => $collections,
            ];
        })->values()->all();
    }

    protected function promptLimit(string $key, int $default): int
    {
        $value = function_exists('config')
            ? config("ai-engine.intelligent_rag.decision.prompt_limits.{$key}", $default)
            : $default;

        return max(1, (int) $value);
    }

    protected function renderBusinessContext(array $context): string
    {
        $lines = [];

        $domain = trim((string) ($context['domain'] ?? ''));
        if ($domain !== '') {
            $lines[] = '- domain: ' . $domain;
        }

        $priorities = array_values(array_filter((array) ($context['priorities'] ?? [])));
        if (!empty($priorities)) {
            $lines[] = '- priorities: ' . implode(', ', $priorities);
        }

        $knownIssues = array_values(array_filter((array) ($context['known_issues'] ?? [])));
        if (!empty($knownIssues)) {
            $lines[] = '- known_issues: ' . implode(' | ', $knownIssues);
        }

        $instructions = array_values(array_filter((array) ($context['instructions'] ?? [])));
        if (!empty($instructions)) {
            $lines[] = '- instructions: ' . implode(' | ', $instructions);
        }

        return empty($lines) ? '(none)' : implode("\n", $lines);
    }

    protected function renderAdaptiveHints(array $hints): string
    {
        if (empty($hints)) {
            return '(none)';
        }

        return implode("\n", array_map(fn (string $hint) => '- ' . $hint, $hints));
    }

    protected function renderPolicyContext(array $selection): string
    {
        $metadata = $selection['metadata'] ?? null;
        if (!is_array($metadata)) {
            return '- selection: template_file';
        }

        $lines = [
            '- selection: ' . ($metadata['selection'] ?? 'active'),
            '- policy_key: ' . ($metadata['policy_key'] ?? 'decision'),
            '- version: ' . ($metadata['version'] ?? 'unknown'),
            '- status: ' . ($metadata['status'] ?? 'unknown'),
        ];

        return implode("\n", $lines);
    }

    protected function fallbackTemplate(): string
    {
        return <<<PROMPT
You are a tool-selection agent for structured data and RAG retrieval.
Choose exactly one tool and return strict JSON only.

USER REQUEST:
{{USER_REQUEST}}

CONTEXT:
{{CONVERSATION}}

VISIBLE LIST CONTEXT:
{{VISIBLE_LIST_CONTEXT}}

SELECTED ENTITY CONTEXT:
{{SELECTED_ENTITY_CONTEXT}}

AVAILABLE MODELS:
{{MODELS_JSON}}

AVAILABLE NODES:
{{NODES_JSON}}

BUSINESS CONTEXT:
{{BUSINESS_CONTEXT}}

ADAPTIVE HINTS:
{{ADAPTIVE_HINTS}}

POLICY CONTEXT:
{{POLICY_CONTEXT}}

TOOLS:
- db_query
- db_query_next
- vector_search
- db_count
- db_aggregate
- answer_from_context
- model_tool
- exit_to_orchestrator

RULES:
1. Reuse selected/visible context for follow-up messages when possible.
2. Avoid re-listing unless user explicitly asks to list/refresh.
3. Use vector_search for conceptual semantic retrieval.
4. Use db_count/db_aggregate for counting and numeric math requests.
5. Use model_tool only for explicit action execution.

RESPONSE FORMAT (JSON only):
{
  "tool": "one_tool_name",
  "reasoning": "one short sentence",
  "parameters": {}
}

For list-like requests include limit {{DEFAULT_LIMIT}} unless user asked a specific size.
PROMPT;
    }
}
