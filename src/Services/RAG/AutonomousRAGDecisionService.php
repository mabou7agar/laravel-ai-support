<?php

namespace LaravelAIEngine\Services\RAG;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Services\AIEngineManager;

/**
 * Decides which RAG tool to invoke for a given user message.
 *
 * The prompt follows a structured decision-tree approach so the LLM
 * can reliably distinguish between:
 *
 *  - **db_query**       → structured data retrieval (list, filter, fetch by ID)
 *  - **db_aggregate**   → numeric computations (sum, avg, min, max)
 *  - **db_count**       → "how many …?" questions
 *  - **vector_search**  → semantic / fuzzy / content-similarity queries
 *  - **answer_from_context** → answer is already visible, no new query needed
 *  - **model_tool**     → execute a CRUD action (create, update, delete)
 *  - **db_query_next**  → pagination continuation
 *  - **exit_to_orchestrator** → multi-model / planning workflows
 */
class AutonomousRAGDecisionService
{
    // ──────────────────────────────────────────────
    //  System prompt — structured decision tree
    // ──────────────────────────────────────────────

    protected const DEFAULT_PROMPT_TEMPLATE = <<<'PROMPT'
You are a tool-routing agent for a data assistant backed by a Laravel application.
Your ONLY job is to pick the single best tool for the user's request and return valid JSON.

═══════════════════════════════════════════════
 DECISION TREE  (evaluate top-to-bottom, pick the FIRST match)
═══════════════════════════════════════════════

1. CONTEXT ALREADY HAS THE ANSWER?
   → If the visible entity list or selected entity context contains enough
     information to answer the question WITHOUT running a new query,
     use **answer_from_context** and put your answer in `parameters.answer`.
   Examples: "what is the status of that invoice?", "who is the customer?"
     when that data is already visible below.

2. PAGINATION REQUEST?
   → Words like "next", "more", "show more", "continue", "next page"
     with NO new filter criteria → **db_query_next** (no parameters needed).

3. CRUD / ACTION REQUEST?
   → User wants to CREATE, UPDATE, DELETE, SEND, MARK, or otherwise
     MUTATE data → **model_tool**.
   Set `parameters.model`, `parameters.tool_name`, `parameters.tool_params`.

4. COUNTING REQUEST?
   → "How many …?", "count of …", "number of …" → **db_count**.
   Set `parameters.model` and optional `parameters.filters`.

5. AGGREGATION / MATH REQUEST?
   → "total", "sum of", "average", "highest", "lowest", "min", "max"
     applied to a numeric field → **db_aggregate**.
   Set `parameters.model`, `parameters.aggregate.operation` (sum|avg|min|max),
   `parameters.aggregate.field`.

6. STRUCTURED DATA RETRIEVAL?
   → User asks to LIST, SHOW, FIND, GET, FETCH, or retrieve data
     from a known model → **db_query**.
   This includes: "list emails", "show my invoices", "get all orders",
   "find emails from John", or any request with specific field filters
   (date, status, ID, name, amount range).
   IMPORTANT: "list [model]" or "show my [model]" is ALWAYS db_query, never vector_search.
   Set `parameters.model` and `parameters.filters` (id, status,
   date_field, date_value, date_operator, amount_min, amount_max, etc.).

7. SEMANTIC / FUZZY / CONTENT SEARCH?
   → User asks a MEANING-based question, wants SIMILAR content,
     or the query cannot be expressed as structured filters.
   Examples: "find emails about the quarterly report",
     "anything related to shipping delays", "search for …"
   → **vector_search**.
   Set `parameters.query` and optionally `parameters.model`.

   KEY DISTINCTION from db_query:
   • db_query = "show me invoices with status=paid" (structured field match)
   • vector_search = "find invoices about construction materials" (semantic meaning)

8. MULTI-MODEL / PLANNING?
   → Request spans multiple models or requires a multi-step plan
     → **exit_to_orchestrator**.

9. FALLBACK
   → If nothing above matches clearly → **db_query** with best-guess model.

═══════════════════════════════════════════════
 CONTEXT
═══════════════════════════════════════════════

User message:
:message

Conversation summary:
:conversation

:last_entity_context
:selected_entity_context

Available models:
:models

Available remote nodes:
:nodes

═══════════════════════════════════════════════
 RESPONSE FORMAT  (JSON only, no markdown fences)
═══════════════════════════════════════════════

{
  "tool": "<tool_name>",
  "reasoning": "<one sentence explaining your choice>",
  "parameters": { ... }
}
PROMPT;

    public function __construct(
        protected AIEngineManager $ai,
        protected array $settings = []
    ) {
    }

    // ──────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────

    public function shouldUseFunctionCalling(string $model, array $options = []): bool
    {
        $mode = $options['function_calling'] ?? $this->setting('function_calling', 'off');
        $normalized = is_string($mode) ? strtolower(trim($mode)) : ($mode ? 'on' : 'off');

        if (in_array($normalized, ['off', 'false', '0', 'disabled'], true)) {
            return false;
        }

        return $this->supportsOpenAIFunctions($model);
    }

    public function decide(string $message, array $context, string $model = 'gpt-4o-mini', array $options = []): array
    {
        $prompt = $this->buildDecisionPrompt($message, $context);

        try {
            Log::channel('ai-engine')->debug('RAGDecisionService prompt', [
                'prompt_length' => strlen($prompt),
            ]);

            $response = $this->ai
                ->model($model)
                ->withTemperature($this->decisionTemperature($options))
                ->withMaxTokens($this->decisionMaxTokens($options))
                ->generate($prompt);

            $content = trim($response->getContent());

            Log::channel('ai-engine')->debug('RAGDecisionService response', [
                'content' => $content,
            ]);

            $decision = $this->parseDecision($content);
            if ($decision !== null) {
                return $decision;
            }

            Log::channel('ai-engine')->warning('RAGDecisionService: parse failed', [
                'content' => substr($content, 0, 300),
            ]);

            return $this->buildFallbackDecision($message, $context, $options);
        } catch (\Exception $e) {
            Log::channel('ai-engine')->error('RAGDecisionService: AI call failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->buildFallbackDecision($message, $context, $options);
        }
    }

    // ──────────────────────────────────────────────
    //  Prompt building
    // ──────────────────────────────────────────────

    protected function buildDecisionPrompt(string $message, array $context): string
    {
        $conversationSummary = $context['conversation'] ?? '(none)';

        $modelsInfo = collect($context['models'] ?? [])->map(function ($m) {
            return [
                'name' => $m['name'] ?? 'unknown',
                'description' => $m['description'] ?? "Model for {$m['name']} data",
                'table' => $m['table'] ?? ($m['name'] ?? 'item') . 's',
                'capabilities' => $m['capabilities'] ?? [],
                'key_fields' => !empty($m['schema']) ? array_keys($m['schema']) : [],
                'tools' => !empty($m['tools']) ? array_keys($m['tools']) : [],
                'location' => $m['location'] ?? 'local',
            ];
        })->toArray();

        $modelsJson = json_encode($modelsInfo, JSON_PRETTY_PRINT);
        $nodesJson = json_encode($context['nodes'] ?? [], JSON_PRETTY_PRINT);

        $lastListContext = $this->buildLastEntityListPromptContext($context);
        $selectedEntityContext = $this->buildSelectedEntityPromptContext($context);
        $template = (string) $this->setting('decision_prompt_template', self::DEFAULT_PROMPT_TEMPLATE);

        return strtr($template, [
            ':message' => $message,
            ':conversation' => $conversationSummary,
            ':last_entity_context' => $lastListContext,
            ':selected_entity_context' => $selectedEntityContext,
            ':models' => $modelsJson ?: '[]',
            ':nodes' => $nodesJson ?: '[]',
        ]);
    }

    protected function buildLastEntityListPromptContext(array $context): string
    {
        if (empty($context['last_entity_list']) || !is_array($context['last_entity_list'])) {
            return '';
        }

        $entityType = $context['last_entity_list']['entity_type'] ?? 'item';
        $entityData = $context['last_entity_list']['entity_data'] ?? [];
        $entityIds = $context['last_entity_list']['entity_ids'] ?? [];
        $startPos = $context['last_entity_list']['start_position'] ?? 1;
        $endPos = $context['last_entity_list']['end_position'] ?? count((array) $entityIds);

        if (empty($entityData)) {
            return '';
        }

        $text = "CURRENTLY VISIBLE {$entityType}s (positions {$startPos}-{$endPos}):\n";
        if (!empty($entityIds)) {
            $text .= 'ENTITY IDS: ' . json_encode($entityIds) . "\n";
        }
        $text .= "DATA PREVIEW:\n" . $this->jsonPreview($entityData) . "\n";

        return $text;
    }

    protected function buildSelectedEntityPromptContext(array $context): string
    {
        if (empty($context['selected_entity']) || !is_array($context['selected_entity'])) {
            return '';
        }

        return "SELECTED ENTITY:\n" . $this->jsonPreview($context['selected_entity']) . "\n";
    }

    // ──────────────────────────────────────────────
    //  Response parsing
    // ──────────────────────────────────────────────

    protected function parseDecision(string $content): ?array
    {
        $clean = trim($content);
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $clean);
        $clean = preg_replace('/\s*```$/m', '', $clean);

        $decision = json_decode($clean, true);
        if (is_array($decision) && isset($decision['tool'])) {
            return $decision;
        }

        // Try extracting JSON object from mixed text
        if (preg_match('/\{[\s\S]*\}/m', $clean, $matches)) {
            $decision = json_decode($matches[0], true);
            if (is_array($decision) && isset($decision['tool'])) {
                return $decision;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Fallback when AI response is unparseable
    // ──────────────────────────────────────────────

    protected function buildFallbackDecision(string $message, array $context, array $options = []): array
    {
        $model = $this->detectModelFromMessage($message, $context);
        $messageLower = strtolower($message);

        // Quick heuristic chain
        if (preg_match('/\b(how many|count|number of)\b/i', $message)) {
            return [
                'tool' => 'db_count',
                'reasoning' => 'Fallback: detected counting intent',
                'parameters' => ['model' => $model ?? 'unknown'],
            ];
        }

        if (preg_match('/\b(total|sum|average|avg|minimum|min|maximum|max)\b/i', $message, $aggMatch)) {
            $opMap = [
                'total' => 'sum', 'sum' => 'sum',
                'average' => 'avg', 'avg' => 'avg',
                'minimum' => 'min', 'min' => 'min',
                'maximum' => 'max', 'max' => 'max',
            ];
            $detectedOp = $opMap[strtolower($aggMatch[1])] ?? 'sum';
            $defaultField = (string) $this->setting('default_aggregate_field', 'amount');

            return [
                'tool' => 'db_aggregate',
                'reasoning' => 'Fallback: detected aggregation intent',
                'parameters' => [
                    'model' => $model ?? 'unknown',
                    'aggregate' => ['operation' => $detectedOp, 'field' => $defaultField],
                ],
            ];
        }

        if (preg_match('/\b(next|more|show more|continue)\b/i', $message)) {
            return [
                'tool' => 'db_query_next',
                'reasoning' => 'Fallback: detected pagination intent',
                'parameters' => [],
            ];
        }

        // Default fallback tool from config
        $fallbackTool = $this->decisionFallbackTool($options);

        if ($fallbackTool === 'vector_search') {
            $params = ['query' => $message, 'limit' => $this->decisionFallbackLimit($options)];
            if ($model !== null) {
                $params['model'] = $model;
            }

            return [
                'tool' => 'vector_search',
                'reasoning' => 'Fallback: using vector_search as default',
                'parameters' => $params,
            ];
        }

        return [
            'tool' => 'db_query',
            'reasoning' => 'Fallback: using db_query as default',
            'parameters' => ['model' => $model ?? 'unknown'],
        ];
    }

    protected function detectModelFromMessage(string $message, array $context): ?string
    {
        $messageLower = strtolower($message);

        foreach (($context['models'] ?? []) as $model) {
            $name = $model['name'] ?? null;
            if (!is_string($name) || trim($name) === '') {
                continue;
            }

            if (stripos($messageLower, strtolower($name)) !== false) {
                return $name;
            }
        }

        // Single-model shortcut
        if (count($context['models'] ?? []) === 1 && !empty($context['models'][0]['name'])) {
            return (string) $context['models'][0]['name'];
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Function-calling support detection
    // ──────────────────────────────────────────────

    protected function supportsOpenAIFunctions(string $model): bool
    {
        $patterns = (array) $this->setting('function_calling_model_patterns', [
            'gpt-4', 'gpt-4-turbo', 'gpt-4o', 'gpt-4o-mini', 'gpt-3.5-turbo',
        ]);

        foreach ($patterns as $pattern) {
            if (stripos($model, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  Config helpers
    // ──────────────────────────────────────────────

    protected function decisionFallbackTool(array $options = []): string
    {
        $tool = $options['decision_fallback_tool'] ?? $this->setting('decision_fallback_tool', 'vector_search');
        $tool = strtolower(trim((string) $tool));

        return in_array($tool, ['vector_search', 'db_query'], true) ? $tool : 'vector_search';
    }

    protected function decisionFallbackLimit(array $options = []): int
    {
        return max(1, min(50, (int) ($options['decision_fallback_limit'] ?? $this->setting('decision_fallback_limit', 10))));
    }

    protected function decisionTemperature(array $options = []): float
    {
        return max(0.0, min(1.0, (float) ($options['decision_temperature'] ?? $this->setting('decision_temperature', 0.1))));
    }

    protected function decisionMaxTokens(array $options = []): int
    {
        return max(64, min(4000, (int) ($options['decision_max_tokens'] ?? $this->setting('decision_max_tokens', 1000))));
    }

    protected function jsonPreview(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT);
        if (!is_string($json)) {
            return '{}';
        }

        $maxChars = max(500, min(12000, (int) $this->setting('context_preview_max_chars', 3000)));

        if (strlen($json) <= $maxChars) {
            return $json;
        }

        return substr($json, 0, $maxChars) . "\n... [truncated]";
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->settings)) {
            return $this->settings[$key];
        }

        try {
            return config("ai-agent.autonomous_rag.{$key}", $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
