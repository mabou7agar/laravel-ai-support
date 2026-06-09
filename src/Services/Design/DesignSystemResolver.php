<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Design;

use LaravelAIEngine\DTOs\DesignSystem;

/**
 * Resolves a complete, grounded DesignSystem from a free-text product brief.
 *
 * Port of the UI/UX Pro Max `design_system.py` reasoning: search the product
 * dataset for a category, apply the reasoning rules for that category, then run
 * style/color/typography/landing searches biased by the recommended style
 * priority and assemble the best matches into a single design system.
 */
class DesignSystemResolver
{
    /** @var array<int, array<string, string>>|null */
    private ?array $reasoning = null;

    public function __construct(
        private readonly DesignKnowledgeBase $knowledge,
    ) {}

    public function resolve(string $query, ?string $projectName = null): DesignSystem
    {
        $query = trim($query);

        // 1. Product category.
        $productMatches = $this->knowledge->search('product', $query, 1);
        $category = $productMatches[0]['Product Type'] ?? 'General';

        // 2. Reasoning rules for the category.
        $reasoning = $this->applyReasoning($category);
        $stylePriority = $reasoning['style_priority'];

        // 3. Domain searches, biasing the style search with priority hints.
        $styleQuery = $stylePriority !== []
            ? trim($query . ' ' . implode(' ', array_slice($stylePriority, 0, 2)))
            : $query;

        $styleResults = $this->knowledge->search('style', $styleQuery, 3);
        $colorResults = $this->knowledge->search('color', $query, 2);
        $typographyResults = $this->knowledge->search('typography', $query, 2);
        $landingResults = $this->knowledge->search('landing', $query, 2);

        // 4. Best matches.
        $bestStyle = $this->selectBestStyle($styleResults, $stylePriority);
        $bestColor = $colorResults[0] ?? [];
        $bestTypography = $typographyResults[0] ?? [];
        $bestLanding = $landingResults[0] ?? [];

        $styleEffects = $bestStyle['Effects & Animation'] ?? '';
        $effects = $styleEffects !== '' ? $styleEffects : $reasoning['key_effects'];

        return new DesignSystem(
            projectName: $projectName !== null && $projectName !== '' ? $projectName : mb_strtoupper($query),
            category: $category,
            pattern: [
                'name' => $bestLanding['Pattern Name'] ?? ($reasoning['pattern'] !== '' ? $reasoning['pattern'] : 'Hero + Features + CTA'),
                'sections' => $bestLanding['Section Order'] ?? 'Hero > Features > CTA',
                'cta_placement' => $bestLanding['Primary CTA Placement'] ?? 'Above fold',
                'color_strategy' => $bestLanding['Color Strategy'] ?? '',
                'conversion' => $bestLanding['Conversion Optimization'] ?? '',
            ],
            style: [
                'name' => $bestStyle['Style Category'] ?? 'Minimalism',
                'type' => $bestStyle['Type'] ?? 'General',
                'effects' => $styleEffects,
                'keywords' => $bestStyle['Keywords'] ?? '',
                'best_for' => $bestStyle['Best For'] ?? '',
                'performance' => $bestStyle['Performance'] ?? '',
                'accessibility' => $bestStyle['Accessibility'] ?? '',
                'light_mode' => $bestStyle['Light Mode ✓'] ?? '',
                'dark_mode' => $bestStyle['Dark Mode ✓'] ?? '',
            ],
            colors: [
                'primary' => $bestColor['Primary'] ?? '#2563EB',
                'on_primary' => $bestColor['On Primary'] ?? '#FFFFFF',
                'secondary' => $bestColor['Secondary'] ?? '#3B82F6',
                'accent' => $bestColor['Accent'] ?? '#F97316',
                'background' => $bestColor['Background'] ?? '#F8FAFC',
                'foreground' => $bestColor['Foreground'] ?? '#1E293B',
                'muted' => $bestColor['Muted'] ?? '',
                'border' => $bestColor['Border'] ?? '',
                'destructive' => $bestColor['Destructive'] ?? '',
                'ring' => $bestColor['Ring'] ?? '',
                'notes' => $bestColor['Notes'] ?? '',
            ],
            typography: [
                'heading' => $bestTypography['Heading Font'] ?? 'Inter',
                'body' => $bestTypography['Body Font'] ?? 'Inter',
                'mood' => $bestTypography['Mood/Style Keywords'] ?? $reasoning['typography_mood'],
                'best_for' => $bestTypography['Best For'] ?? '',
                'google_fonts_url' => $bestTypography['Google Fonts URL'] ?? '',
                'css_import' => $bestTypography['CSS Import'] ?? '',
            ],
            keyEffects: $effects,
            antiPatterns: $reasoning['anti_patterns'],
            decisionRules: $reasoning['decision_rules'],
            severity: $reasoning['severity'],
        );
    }

    /**
     * @return array{pattern: string, style_priority: array<int, string>, color_mood: string, typography_mood: string, key_effects: string, anti_patterns: string, decision_rules: array<string, mixed>, severity: string}
     */
    private function applyReasoning(string $category): array
    {
        $rule = $this->findReasoningRule($category);

        if ($rule === []) {
            return [
                'pattern' => 'Hero + Features + CTA',
                'style_priority' => ['Minimalism', 'Flat Design'],
                'color_mood' => 'Professional',
                'typography_mood' => 'Clean',
                'key_effects' => 'Subtle hover transitions',
                'anti_patterns' => '',
                'decision_rules' => [],
                'severity' => 'MEDIUM',
            ];
        }

        $decisionRules = [];
        $decoded = json_decode($rule['Decision_Rules'] ?? '{}', true);
        if (is_array($decoded)) {
            $decisionRules = $decoded;
        }

        $stylePriority = array_values(array_filter(array_map(
            'trim',
            explode('+', $rule['Style_Priority'] ?? '')
        ), static fn (string $s): bool => $s !== ''));

        return [
            'pattern' => trim($rule['Recommended_Pattern'] ?? ''),
            'style_priority' => $stylePriority,
            'color_mood' => trim($rule['Color_Mood'] ?? ''),
            'typography_mood' => trim($rule['Typography_Mood'] ?? ''),
            'key_effects' => trim($rule['Key_Effects'] ?? ''),
            'anti_patterns' => trim($rule['Anti_Patterns'] ?? ''),
            'decision_rules' => $decisionRules,
            'severity' => trim($rule['Severity'] ?? 'MEDIUM'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function findReasoningRule(string $category): array
    {
        $rules = $this->reasoningRules();
        $categoryLower = mb_strtolower($category);

        // Exact match.
        foreach ($rules as $rule) {
            if (mb_strtolower($rule['UI_Category'] ?? '') === $categoryLower) {
                return $rule;
            }
        }

        // Partial (substring either direction).
        foreach ($rules as $rule) {
            $uiCat = mb_strtolower($rule['UI_Category'] ?? '');
            if ($uiCat !== '' && (str_contains($categoryLower, $uiCat) || str_contains($uiCat, $categoryLower))) {
                return $rule;
            }
        }

        // Keyword match.
        foreach ($rules as $rule) {
            $uiCat = mb_strtolower($rule['UI_Category'] ?? '');
            $keywords = preg_split('/\s+/', str_replace(['/', '-'], ' ', $uiCat)) ?: [];
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($categoryLower, $kw)) {
                    return $rule;
                }
            }
        }

        return [];
    }

    /**
     * Select the best style row, biased by the recommended style priority.
     * Mirrors design_system.py `_select_best_match`.
     *
     * @param array<int, array<string, string>> $results
     * @param array<int, string> $priority
     * @return array<string, string>
     */
    private function selectBestStyle(array $results, array $priority): array
    {
        if ($results === []) {
            return [];
        }

        if ($priority === []) {
            return $results[0];
        }

        // Exact style-name match.
        foreach ($priority as $p) {
            $pLower = mb_strtolower(trim($p));
            foreach ($results as $result) {
                $styleName = mb_strtolower($result['Style Category'] ?? '');
                if ($styleName !== '' && (str_contains($styleName, $pLower) || str_contains($pLower, $styleName))) {
                    return $result;
                }
            }
        }

        // Weighted keyword scoring.
        $best = $results[0];
        $bestScore = 0;
        foreach ($results as $result) {
            $resultStr = mb_strtolower(implode(' ', $result));
            $score = 0;
            foreach ($priority as $kw) {
                $kwLower = mb_strtolower(trim($kw));
                if ($kwLower === '') {
                    continue;
                }
                if (str_contains(mb_strtolower($result['Style Category'] ?? ''), $kwLower)) {
                    $score += 10;
                } elseif (str_contains(mb_strtolower($result['Keywords'] ?? ''), $kwLower)) {
                    $score += 3;
                } elseif (str_contains($resultStr, $kwLower)) {
                    $score += 1;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function reasoningRules(): array
    {
        if ($this->reasoning !== null) {
            return $this->reasoning;
        }

        $path = $this->knowledge->dataPath() . DIRECTORY_SEPARATOR . 'ui-reasoning.csv';

        $rows = [];
        if (is_file($path) && ($handle = fopen($path, 'r')) !== false) {
            $header = fgetcsv($handle);
            if (is_array($header)) {
                $header = array_map(static fn ($h): string => is_string($h) ? trim($h, "\xEF\xBB\xBF \t") : (string) $h, $header);
                while (($data = fgetcsv($handle)) !== false) {
                    if ($data === [null]) {
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
        }

        return $this->reasoning = $rows;
    }
}
