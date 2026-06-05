<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;

/**
 * Progressive-disclosure helper. When tool disclosure is set to "progressive", the prompt
 * lists tools by name + one-line description only (cheap); this tool returns the FULL
 * parameter schema for the tools matching a query, so the planner can load just the
 * schema it needs before calling a tool — keeping the base prompt small even with a large
 * registry. (The same pattern as deferred tools elsewhere.)
 */
class FindToolsTool extends AgentTool
{
    public function __construct(private readonly ToolRegistry $tools)
    {
    }

    public function getName(): string
    {
        return 'find_tools';
    }

    public function getDescription(): string
    {
        return 'Find tools by keyword and get their full parameter schemas. Tools are listed by '
            . 'name and summary only; call this to load a tool\'s parameters before you use it.';
    }

    public function getParameters(): array
    {
        return [
            'query' => ['type' => 'string', 'required' => true, 'description' => 'What you want to do (keywords or a tool name).'],
            'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Max tools to return (default 8).'],
        ];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        $query = trim((string) ($parameters['query'] ?? ''));
        if ($query === '') {
            return ActionResult::failure('A query is required to find tools.', ['found' => 0]);
        }

        $limit = max(1, min(25, (int) ($parameters['limit'] ?? 8)));
        $terms = $this->terms($query);

        $scored = [];
        foreach ($this->tools->all() as $name => $tool) {
            if ($name === $this->getName()) {
                continue;
            }
            $score = $this->score($name, (string) $tool->getDescription(), $query, $terms);
            if ($score > 0) {
                $scored[$name] = $score;
            }
        }

        arsort($scored);
        $matches = array_slice(array_keys($scored), 0, $limit);

        $toolDocs = [];
        foreach ($matches as $name) {
            $tool = $this->tools->get($name);
            if ($tool !== null) {
                $toolDocs[] = $tool->toArray();
            }
        }

        return ActionResult::success(
            $toolDocs === [] ? 'No matching tools found.' : 'Loaded ' . count($toolDocs) . ' tool schema(s).',
            ['found' => count($toolDocs), 'tools' => $toolDocs]
        );
    }

    /**
     * @param array<int, string> $terms
     */
    private function score(string $name, string $description, string $query, array $terms): int
    {
        $lowerName = mb_strtolower($name);
        // Exact tool-name request wins.
        if ($lowerName === mb_strtolower($query)) {
            return 1000;
        }

        $nameHay = ' ' . str_replace('_', ' ', $lowerName) . ' ';
        $descHay = mb_strtolower($description);
        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($nameHay, ' ' . $term . ' ')) {
                $score += 3;
            } elseif (str_contains($nameHay, $term)) {
                $score += 2;
            } elseif (str_contains($descHay, $term)) {
                $score += 1;
            }
        }

        return $score;
    }

    /**
     * @return array<int, string>
     */
    private function terms(string $query): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', mb_strtolower($query)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            static fn (string $t): bool => strlen($t) >= 2
        )));
    }
}
