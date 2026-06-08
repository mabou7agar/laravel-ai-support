<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Agent\Tools\Selectors;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Services\Vector\EmbeddingService;

/**
 * Semantic top-K selector: embed the turn message and each tool's name+description, rank
 * tools by cosine similarity, and expose the best `limit` (plus the always-on core). Tool
 * embeddings are static, so they are cached per (name,description); only the message is
 * embedded each turn. For large, flat tool registries that can't be skill-scoped.
 *
 * Fails OPEN: if embedding is unavailable or errors, fall back to all tools rather than
 * starve the planner. This is the most expensive strategy (one embedding call per turn) —
 * prefer skill_scoped or keyword unless you genuinely have many unrelated tools.
 */
class SemanticToolSelector implements ToolSelectorContract
{
    use SelectsBoundedTools;

    public function __construct(private readonly EmbeddingService $embeddings)
    {
    }

    public function select(array $tools, string $message, array $state, array $options): array
    {
        $message = trim($message);
        if ($message === '') {
            return $this->fallbackTools($tools);
        }

        $limit = max(1, (int) config('ai-agent.ai_native.tool_selection.limit', 12));
        $coreNames = array_flip($this->core());

        $core = [];
        $candidates = [];
        foreach ($tools as $name => $tool) {
            if (isset($coreNames[$name])) {
                $core[$name] = $tool;
            } else {
                $candidates[$name] = $tool;
            }
        }

        if ($candidates === []) {
            return $this->fallbackTools($tools);
        }

        try {
            $messageVector = $this->embeddings->embed($message);

            $scored = [];
            foreach ($candidates as $name => $tool) {
                $scored[$name] = $this->embeddings->cosineSimilarity(
                    $messageVector,
                    $this->toolVector($name, $tool)
                );
            }
        } catch (\Throwable) {
            // Embedding unavailable/errored — fail open, but bounded so a large registry
            // doesn't dump every schema into the prompt.
            return $this->fallbackTools($tools);
        }

        arsort($scored);
        $room = max(0, $limit - count($core));
        $picked = array_slice($scored, 0, $room, true);

        $selected = $core;
        foreach (array_keys($picked) as $name) {
            $selected[$name] = $candidates[$name];
        }

        return $selected !== [] ? $selected : $tools;
    }

    /**
     * @return array<int, float>
     */
    private function toolVector(string $name, object $tool): array
    {
        $text = str_replace('_', ' ', $name) . ': ' . $tool->getDescription();
        $key = 'ai-engine:tool-embedding:' . sha1($name . '|' . $tool->getDescription());

        return Cache::rememberForever($key, fn (): array => $this->embeddings->embed($text));
    }
}
