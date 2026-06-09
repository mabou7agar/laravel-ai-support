<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

/**
 * Self-contained design-intelligence knowledge base.
 *
 * Loads the bundled UI/UX design datasets (CSV) and ranks rows against a query
 * using BM25. This is a faithful PHP port of the UI/UX Pro Max `core.py` search
 * engine, so the package carries no Python runtime dependency.
 */
class DesignKnowledgeBase
{
    private const K1 = 1.5;
    private const B = 0.75;

    /**
     * Per-domain dataset configuration: which file, which columns are searched,
     * and which columns are returned.
     *
     * @var array<string, array{file: string, search: array<int, string>, output: array<int, string>}>
     */
    private const DOMAINS = [
        'product' => [
            'file' => 'products.csv',
            'search' => ['Product Type', 'Keywords', 'Primary Style Recommendation', 'Key Considerations'],
            'output' => ['Product Type', 'Keywords', 'Primary Style Recommendation', 'Secondary Styles', 'Landing Page Pattern', 'Dashboard Style (if applicable)', 'Color Palette Focus'],
        ],
        'style' => [
            'file' => 'styles.csv',
            'search' => ['Style Category', 'Keywords', 'Best For', 'Type', 'AI Prompt Keywords'],
            'output' => ['Style Category', 'Type', 'Keywords', 'Primary Colors', 'Effects & Animation', 'Best For', 'Light Mode ✓', 'Dark Mode ✓', 'Performance', 'Accessibility', 'Framework Compatibility', 'Complexity', 'AI Prompt Keywords', 'CSS/Technical Keywords', 'Implementation Checklist', 'Design System Variables'],
        ],
        'color' => [
            'file' => 'colors.csv',
            'search' => ['Product Type', 'Notes'],
            'output' => ['Product Type', 'Primary', 'On Primary', 'Secondary', 'On Secondary', 'Accent', 'On Accent', 'Background', 'Foreground', 'Card', 'Card Foreground', 'Muted', 'Muted Foreground', 'Border', 'Destructive', 'On Destructive', 'Ring', 'Notes'],
        ],
        'typography' => [
            'file' => 'typography.csv',
            'search' => ['Font Pairing Name', 'Category', 'Mood/Style Keywords', 'Best For', 'Heading Font', 'Body Font'],
            'output' => ['Font Pairing Name', 'Category', 'Heading Font', 'Body Font', 'Mood/Style Keywords', 'Best For', 'Google Fonts URL', 'CSS Import', 'Tailwind Config', 'Notes'],
        ],
        'landing' => [
            'file' => 'landing.csv',
            'search' => ['Pattern Name', 'Keywords', 'Conversion Optimization', 'Section Order'],
            'output' => ['Pattern Name', 'Keywords', 'Section Order', 'Primary CTA Placement', 'Color Strategy', 'Conversion Optimization'],
        ],
        'ux' => [
            'file' => 'ux-guidelines.csv',
            'search' => ['Category', 'Issue', 'Description', 'Platform'],
            'output' => ['Category', 'Issue', 'Platform', 'Description', 'Do', "Don't", 'Severity'],
        ],
    ];

    private string $dataPath;

    /**
     * In-memory cache of loaded CSV rows keyed by domain.
     *
     * @var array<string, array<int, array<string, string>>>
     */
    private array $cache = [];

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = self::resolveDataPath($dataPath);
    }

    /**
     * The directory the datasets are read from.
     */
    public function dataPath(): string
    {
        return $this->dataPath;
    }

    /**
     * Resolution order: explicit argument → configured path → published copy
     * (resources/vendor/ai-engine/design-intelligence) → bundled default.
     */
    public static function resolveDataPath(?string $dataPath = null): string
    {
        $configured = is_string($dataPath) && $dataPath !== ''
            ? $dataPath
            : (string) config('ai-engine.design.data_path', '');

        if ($configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        if (function_exists('resource_path')) {
            $published = resource_path('vendor/ai-engine/design-intelligence');
            if (is_dir($published) && is_file($published . DIRECTORY_SEPARATOR . 'products.csv')) {
                return rtrim($published, DIRECTORY_SEPARATOR);
            }
        }

        return self::defaultDataPath();
    }

    public static function defaultDataPath(): string
    {
        return realpath(__DIR__ . '/../../../resources/design-intelligence')
            ?: __DIR__ . '/../../../resources/design-intelligence';
    }

    /**
     * @return array<int, string>
     */
    public function domains(): array
    {
        return array_keys(self::DOMAINS);
    }

    /**
     * Rank dataset rows for a domain against the query (BM25). Returns only the
     * configured output columns for rows scoring above zero, best first.
     *
     * @return array<int, array<string, string>>
     */
    public function search(string $domain, string $query, int $maxResults = 3): array
    {
        if (!isset(self::DOMAINS[$domain])) {
            return [];
        }

        $config = self::DOMAINS[$domain];
        $rows = $this->load($domain);
        if ($rows === []) {
            return [];
        }

        $documents = array_map(
            static fn (array $row): string => implode(' ', array_map(
                static fn (string $col): string => $row[$col] ?? '',
                $config['search']
            )),
            $rows
        );

        $ranked = $this->bm25($documents, $query);

        $results = [];
        foreach (array_slice($ranked, 0, max(0, $maxResults)) as [$index, $score]) {
            if ($score <= 0.0) {
                continue;
            }

            $row = $rows[$index];
            $picked = [];
            foreach ($config['output'] as $col) {
                if (array_key_exists($col, $row)) {
                    $picked[$col] = $row[$col];
                }
            }
            $results[] = $picked;
        }

        return $results;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function load(string $domain): array
    {
        if (isset($this->cache[$domain])) {
            return $this->cache[$domain];
        }

        $file = $this->dataPath . DIRECTORY_SEPARATOR . self::DOMAINS[$domain]['file'];
        if (!is_file($file)) {
            return $this->cache[$domain] = [];
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return $this->cache[$domain] = [];
        }

        $rows = [];
        $header = fgetcsv($handle);
        if (is_array($header)) {
            $header = array_map(static fn ($h): string => is_string($h) ? trim($h, "\xEF\xBB\xBF \t") : (string) $h, $header);
            while (($data = fgetcsv($handle)) !== false) {
                if ($data === [null] || $data === false) {
                    continue;
                }
                $row = [];
                foreach ($header as $i => $col) {
                    $row[$col] = isset($data[$i]) ? (string) $data[$i] : '';
                }
                $rows[] = $row;
            }
        }
        fclose($handle);

        return $this->cache[$domain] = $rows;
    }

    /**
     * BM25 ranking. Returns [index, score] pairs sorted by score descending.
     *
     * @param array<int, string> $documents
     * @return array<int, array{0: int, 1: float}>
     */
    private function bm25(array $documents, string $query): array
    {
        $corpus = array_map([$this, 'tokenize'], $documents);
        $n = count($corpus);
        if ($n === 0) {
            return [];
        }

        $docLengths = array_map('count', $corpus);
        $avgdl = array_sum($docLengths) / $n;
        if ($avgdl <= 0.0) {
            $avgdl = 1.0;
        }

        // Document frequency per term.
        $docFreqs = [];
        foreach ($corpus as $tokens) {
            foreach (array_unique($tokens) as $term) {
                $docFreqs[$term] = ($docFreqs[$term] ?? 0) + 1;
            }
        }

        $idf = [];
        foreach ($docFreqs as $term => $freq) {
            $idf[$term] = log(($n - $freq + 0.5) / ($freq + 0.5) + 1);
        }

        $queryTokens = $this->tokenize($query);

        $scores = [];
        foreach ($corpus as $idx => $tokens) {
            $score = 0.0;
            $docLen = $docLengths[$idx];

            $termFreqs = [];
            foreach ($tokens as $term) {
                $termFreqs[$term] = ($termFreqs[$term] ?? 0) + 1;
            }

            foreach ($queryTokens as $token) {
                if (!isset($idf[$token])) {
                    continue;
                }
                $tf = $termFreqs[$token] ?? 0;
                if ($tf === 0) {
                    continue;
                }
                $numerator = $tf * (self::K1 + 1);
                $denominator = $tf + self::K1 * (1 - self::B + self::B * $docLen / $avgdl);
                $score += $idf[$token] * $numerator / $denominator;
            }

            $scores[] = [$idx, $score];
        }

        usort($scores, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return $scores;
    }

    /**
     * Lowercase, strip punctuation, drop tokens of length <= 2.
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text) ?? $text;
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        return array_values(array_filter($tokens, static fn (string $w): bool => mb_strlen($w) > 2));
    }
}
