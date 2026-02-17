<?php

namespace LaravelAIEngine\Services\Agent\Handlers;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIEngineService;

/**
 * Handles the THOUGHT → ACTION → OBSERVATION reasoning loop.
 *
 * Pure orchestration: builds prompts, calls AI, parses output.
 * Does NOT execute tools — delegates that to the caller via callback.
 */
class AgentReasoningLoop
{
    protected int $maxIterations;

    public function __construct(
        protected AIEngineService $ai,
        protected array $settings = []
    ) {
        $this->maxIterations = (int) ($settings['max_iterations'] ?? config('ai-agent.agent_executor.max_iterations', 5));
    }

    /**
     * Run the reasoning loop.
     *
     * @param string   $message           User message
     * @param string   $toolSchemaText    Formatted tool descriptions for the prompt
     * @param string   $conversationSnippet  Recent conversation history
     * @param string   $entityContext     Selected entity JSON or 'none'
     * @param callable $toolExecutor      fn(string $toolName, array $params): string — executes a tool, returns observation
     * @return array{message: string, metadata: array}
     */
    public function run(
        string $message,
        string $toolSchemaText,
        string $conversationSnippet,
        string $entityContext,
        callable $toolExecutor
    ): array {
        $scratchpad = '';
        $lastResult = null;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $prompt = $this->buildPrompt(
                $message,
                $toolSchemaText,
                $conversationSnippet,
                $entityContext,
                $scratchpad
            );

            try {
                $aiResponse = $this->ai->generate(new AIRequest(
                    prompt: $prompt,
                    engine: $this->resolveEngine(),
                    model: $this->resolveModel(),
                    maxTokens: (int) ($this->settings['max_tokens'] ?? 400),
                    temperature: 0.1
                ));

                $raw = trim($aiResponse->getContent());

                Log::channel('ai-engine')->debug('AgentReasoningLoop iteration', [
                    'iteration' => $i + 1,
                    'raw' => substr($raw, 0, 500),
                ]);

                $parsed = $this->parseOutput($raw);

                // FINAL_ANSWER — agent is done reasoning
                if ($parsed['type'] === 'final_answer') {
                    return $this->buildResult($parsed['content'], $lastResult);
                }

                // ACTION — agent wants to call a tool
                if ($parsed['type'] === 'action') {
                    $observation = $toolExecutor($parsed['tool'], $parsed['params']);
                    $lastResult = $observation;

                    $scratchpad .= "THOUGHT: {$parsed['thought']}\n";
                    $scratchpad .= "ACTION: {$parsed['tool']}(" . json_encode($parsed['params'], JSON_UNESCAPED_UNICODE) . ")\n";
                    $scratchpad .= "OBSERVATION: {$observation}\n\n";
                    continue;
                }

                // Unparseable — treat as final answer
                return $this->buildResult($raw, $lastResult);

            } catch (\Exception $e) {
                Log::channel('ai-engine')->error('AgentReasoningLoop iteration failed', [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ]);
                $scratchpad .= "OBSERVATION: Error — {$e->getMessage()}\n\n";
            }
        }

        // Max iterations reached
        Log::channel('ai-engine')->warning('AgentReasoningLoop hit max iterations', [
            'max' => $this->maxIterations,
        ]);

        $fallbackMessage = $lastResult
            ? "I completed some steps but couldn't finish the full operation. Here's what I found:\n\n{$lastResult}"
            : 'I was unable to complete this operation. Please try rephrasing your request.';

        return $this->buildResult($fallbackMessage, $lastResult);
    }

    // ──────────────────────────────────────────────
    //  Prompt
    // ──────────────────────────────────────────────

    protected function buildPrompt(
        string $message,
        string $toolSchema,
        string $conversationHistory,
        string $entityContext,
        string $scratchpad
    ): string {
        $prompt = <<<PROMPT
You are an AI agent that can use tools to help the user. Think step by step.

AVAILABLE TOOLS:
{$toolSchema}

CONVERSATION CONTEXT:
{$conversationHistory}

SELECTED ENTITY: {$entityContext}

USER REQUEST: "{$message}"
PROMPT;

        if ($scratchpad !== '') {
            $prompt .= <<<PROMPT


PREVIOUS STEPS:
{$scratchpad}
Continue from where you left off.
PROMPT;
        }

        $prompt .= <<<PROMPT


RESPONSE FORMAT — use EXACTLY one of these:

Option A (need to call a tool):
THOUGHT: <your reasoning about what to do next>
ACTION: <tool_name>
PARAMS: <JSON object of parameters>

Option B (ready to answer the user):
THOUGHT: <your reasoning>
FINAL_ANSWER: <your complete response to the user>

RULES:
- Call ONE tool at a time, then wait for the observation
- If a tool returns an error, try a different approach
- Some tools are on remote nodes — they work the same way, just call them by name
- When you have enough information, use FINAL_ANSWER
- FINAL_ANSWER must be a helpful, natural-language response
- Include relevant data from tool results in your final answer
- After FINAL_ANSWER, suggest 2-3 things the user can do next as bullet points starting with "You can also:"
PROMPT;

        return $prompt;
    }

    // ──────────────────────────────────────────────
    //  Output parsing
    // ──────────────────────────────────────────────

    /**
     * @return array{type: string, thought?: string, tool?: string, params?: array, content?: string}
     */
    public function parseOutput(string $raw): array
    {
        // Check for FINAL_ANSWER
        if (preg_match('/FINAL_ANSWER:\s*(.+)/s', $raw, $m)) {
            $thought = '';
            if (preg_match('/THOUGHT:\s*(.+?)(?=FINAL_ANSWER)/s', $raw, $tm)) {
                $thought = trim($tm[1]);
            }
            return [
                'type' => 'final_answer',
                'thought' => $thought,
                'content' => trim($m[1]),
            ];
        }

        // Check for ACTION + PARAMS
        if (preg_match('/ACTION:\s*(\S+)/i', $raw, $actionMatch)) {
            $toolName = trim($actionMatch[1]);

            $thought = '';
            if (preg_match('/THOUGHT:\s*(.+?)(?=ACTION)/s', $raw, $tm)) {
                $thought = trim($tm[1]);
            }

            $params = [];
            if (preg_match('/PARAMS:\s*(\{.+\})/s', $raw, $pm)) {
                $decoded = json_decode(trim($pm[1]), true);
                if (is_array($decoded)) {
                    $params = $decoded;
                }
            }

            return [
                'type' => 'action',
                'thought' => $thought,
                'tool' => $toolName,
                'params' => $params,
            ];
        }

        // Unparseable
        return [
            'type' => 'final_answer',
            'thought' => '',
            'content' => $raw,
        ];
    }

    // ──────────────────────────────────────────────
    //  Result building
    // ──────────────────────────────────────────────

    protected function buildResult(string $content, ?string $lastToolResult): array
    {
        $suggestedActions = $this->extractSuggestedActions($content);
        $cleanContent = $this->removeSuggestedActionsBlock($content);

        $metadata = ['strategy' => 'agent_tool_executor'];

        if (!empty($suggestedActions)) {
            $metadata['suggested_next_actions'] = $suggestedActions;
        }

        if ($lastToolResult) {
            $decoded = json_decode($lastToolResult, true);
            if (is_array($decoded)) {
                if (!empty($decoded['entity_ids'])) {
                    $metadata['entity_ids'] = $decoded['entity_ids'];
                }
                if (!empty($decoded['data']['id'])) {
                    $metadata['entity_ids'] = [$decoded['data']['id']];
                }
            }
        }

        return ['message' => $cleanContent, 'metadata' => $metadata];
    }

    protected function extractSuggestedActions(string $content): array
    {
        $actions = [];
        if (preg_match('/You can also:\s*\n((?:\s*[-•]\s*.+\n?)+)/i', $content, $m)) {
            $lines = preg_split('/\n/', trim($m[1]));
            foreach ($lines as $line) {
                $line = trim(preg_replace('/^[-•]\s*/', '', trim($line)));
                if ($line !== '') {
                    $actions[] = $line;
                }
            }
        }
        return $actions;
    }

    protected function removeSuggestedActionsBlock(string $content): string
    {
        return trim(preg_replace('/\n*You can also:\s*\n((?:\s*[-•]\s*.+\n?)+)/i', '', $content));
    }

    // ──────────────────────────────────────────────
    //  Config
    // ──────────────────────────────────────────────

    protected function resolveEngine(): EngineEnum
    {
        $engine = (string) ($this->settings['engine'] ?? config('ai-agent.agent_executor.engine', config('ai-engine.default', 'openai')));
        return EngineEnum::from($engine);
    }

    protected function resolveModel(): EntityEnum
    {
        $model = (string) ($this->settings['model'] ?? config('ai-agent.agent_executor.model', config('ai-engine.orchestration_model', 'gpt-4o-mini')));
        return EntityEnum::from($model);
    }
}
