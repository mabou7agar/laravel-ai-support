<?php

namespace LaravelAIEngine\Services\Node;

use Illuminate\Support\Str;

/**
 * Shared name-matching utility for node/model/collection resolution.
 *
 * Consolidates the singular/plural/fuzzy matching logic that was
 * previously duplicated across NodeRegistryService, NodeRoutingCoordinator,
 * RAGModelDiscovery, and NodeRouterService.
 *
 * All comparisons are case-insensitive and strip non-alphanumeric chars
 * when doing normalized matching.
 */
class NodeNameMatcher
{
    /**
     * Check if two names refer to the same entity.
     *
     * Handles singular/plural, class basenames, and normalized forms.
     *
     *   matches('invoice', 'invoices')   → true
     *   matches('Invoice', 'invoice')    → true
     *   matches('App\Models\Invoice', 'invoice') → true (via basename)
     */
    public static function matches(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return false;
        }

        $a = strtolower(trim($a));
        $b = strtolower(trim($b));

        if ($a === $b) {
            return true;
        }

        // Singular/plural
        if ($a . 's' === $b || $b . 's' === $a) {
            return true;
        }

        // Laravel-style pluralization
        $aPlural = strtolower(Str::plural($a));
        $bPlural = strtolower(Str::plural($b));
        $aSingular = strtolower(Str::singular($a));
        $bSingular = strtolower(Str::singular($b));

        if ($aSingular === $bSingular) {
            return true;
        }

        if ($a === $bPlural || $aPlural === $b) {
            return true;
        }

        return false;
    }

    /**
     * Check if a candidate name matches a requested name,
     * including substring containment for compound names.
     *
     *   contains('salesinvoice', 'invoice') → true
     *   contains('invoice', 'salesinvoice') → false (candidate must contain requested)
     */
    public static function contains(string $candidate, string $requested): bool
    {
        if (static::matches($candidate, $requested)) {
            return true;
        }

        $candidate = strtolower(trim($candidate));
        $requested = strtolower(trim($requested));

        return str_contains($candidate, $requested);
    }

    /**
     * Normalize a string for comparison: lowercase, strip non-alphanumeric.
     */
    public static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Check if two strings match after normalization.
     *
     *   normalizedMatch('my-node', 'mynode')   → true
     *   normalizedMatch('My Node', 'my_node')  → true
     */
    public static function normalizedMatch(string $a, string $b): bool
    {
        $na = static::normalize($a);
        $nb = static::normalize($b);

        if ($na === '' || $nb === '') {
            return false;
        }

        return $na === $nb;
    }

    /**
     * Score how well a candidate matches a query string.
     *
     * Returns 0 for no match, higher scores for better matches.
     * Useful for ranked node/collection selection.
     *
     * @param string $candidate  The name to test (e.g. node slug, collection name)
     * @param string $query      The search query or requested name
     * @param array  $aliases    Additional aliases for the candidate (keywords, domains, etc.)
     * @return int Score (0 = no match)
     */
    public static function score(string $candidate, string $query, array $aliases = []): int
    {
        $score = 0;
        $queryLower = strtolower(trim($query));
        $candidateLower = strtolower(trim($candidate));

        if ($queryLower === '' || $candidateLower === '') {
            return 0;
        }

        // Exact match
        if ($candidateLower === $queryLower) {
            return 100;
        }

        // Singular/plural match
        if (static::matches($candidateLower, $queryLower)) {
            $score = max($score, 90);
        }

        // Normalized match (strips special chars)
        if (static::normalizedMatch($candidateLower, $queryLower)) {
            $score = max($score, 85);
        }

        // Candidate contains query
        if (str_contains($candidateLower, $queryLower)) {
            $score = max($score, 70);
        }

        // Query contains candidate (weaker signal)
        if (str_contains($queryLower, $candidateLower)) {
            $score = max($score, 50);
        }

        // Check aliases
        foreach ($aliases as $alias) {
            $aliasLower = strtolower(trim((string) $alias));
            if ($aliasLower === '') {
                continue;
            }

            if ($aliasLower === $queryLower || static::matches($aliasLower, $queryLower)) {
                $score = max($score, 80);
            } elseif (str_contains($queryLower, $aliasLower)) {
                $score = max($score, 40);
            }
        }

        return $score;
    }

    /**
     * Check if a model class string matches a collection name.
     *
     * Handles both FQCN and simple name comparisons:
     *   matchesClass('App\Models\Invoice', 'invoice')  → true
     *   matchesClass('App\Models\Invoice', 'invoices') → true
     */
    public static function matchesClass(string $className, string $name): bool
    {
        $basename = strtolower(class_basename($className));
        return static::matches($basename, $name);
    }
}
