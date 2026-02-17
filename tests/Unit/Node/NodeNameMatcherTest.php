<?php

namespace LaravelAIEngine\Tests\Unit\Node;

use PHPUnit\Framework\TestCase;
use LaravelAIEngine\Services\Node\NodeNameMatcher;

class NodeNameMatcherTest extends TestCase
{
    // ──────────────────────────────────────────────
    //  matches()
    // ──────────────────────────────────────────────

    public function test_matches_exact(): void
    {
        $this->assertTrue(NodeNameMatcher::matches('invoice', 'invoice'));
    }

    public function test_matches_case_insensitive(): void
    {
        $this->assertTrue(NodeNameMatcher::matches('Invoice', 'invoice'));
        $this->assertTrue(NodeNameMatcher::matches('INVOICE', 'invoice'));
    }

    public function test_matches_simple_plural(): void
    {
        $this->assertTrue(NodeNameMatcher::matches('invoice', 'invoices'));
        $this->assertTrue(NodeNameMatcher::matches('invoices', 'invoice'));
    }

    public function test_matches_laravel_plural(): void
    {
        $this->assertTrue(NodeNameMatcher::matches('category', 'categories'));
        $this->assertTrue(NodeNameMatcher::matches('person', 'people'));
    }

    public function test_matches_empty_strings(): void
    {
        $this->assertFalse(NodeNameMatcher::matches('', 'invoice'));
        $this->assertFalse(NodeNameMatcher::matches('invoice', ''));
        $this->assertFalse(NodeNameMatcher::matches('', ''));
    }

    public function test_matches_unrelated(): void
    {
        $this->assertFalse(NodeNameMatcher::matches('invoice', 'email'));
        $this->assertFalse(NodeNameMatcher::matches('patient', 'product'));
    }

    // ──────────────────────────────────────────────
    //  contains()
    // ──────────────────────────────────────────────

    public function test_contains_exact_match(): void
    {
        $this->assertTrue(NodeNameMatcher::contains('invoice', 'invoice'));
    }

    public function test_contains_substring(): void
    {
        $this->assertTrue(NodeNameMatcher::contains('salesinvoice', 'invoice'));
    }

    public function test_contains_not_reverse(): void
    {
        // "invoice" does not contain "salesinvoice"
        $this->assertFalse(NodeNameMatcher::contains('invoice', 'salesinvoice'));
    }

    public function test_contains_singular_plural(): void
    {
        $this->assertTrue(NodeNameMatcher::contains('invoices', 'invoice'));
    }

    // ──────────────────────────────────────────────
    //  normalize() + normalizedMatch()
    // ──────────────────────────────────────────────

    public function test_normalize_strips_special_chars(): void
    {
        $this->assertSame('mynode', NodeNameMatcher::normalize('my-node'));
        $this->assertSame('mynode', NodeNameMatcher::normalize('My Node'));
        $this->assertSame('mynode', NodeNameMatcher::normalize('my_node'));
    }

    public function test_normalized_match(): void
    {
        $this->assertTrue(NodeNameMatcher::normalizedMatch('my-node', 'mynode'));
        $this->assertTrue(NodeNameMatcher::normalizedMatch('My Node', 'my_node'));
    }

    public function test_normalized_match_empty(): void
    {
        $this->assertFalse(NodeNameMatcher::normalizedMatch('', 'test'));
        $this->assertFalse(NodeNameMatcher::normalizedMatch('test', ''));
    }

    // ──────────────────────────────────────────────
    //  score()
    // ──────────────────────────────────────────────

    public function test_score_exact_match_is_100(): void
    {
        $this->assertSame(100, NodeNameMatcher::score('invoice', 'invoice'));
    }

    public function test_score_singular_plural_is_90(): void
    {
        $this->assertSame(90, NodeNameMatcher::score('invoice', 'invoices'));
    }

    public function test_score_no_match_is_0(): void
    {
        $this->assertSame(0, NodeNameMatcher::score('invoice', 'weather'));
    }

    public function test_score_contains_candidate(): void
    {
        $score = NodeNameMatcher::score('salesinvoice', 'invoice');
        $this->assertGreaterThanOrEqual(70, $score);
    }

    public function test_score_alias_match(): void
    {
        $score = NodeNameMatcher::score('billing', 'invoice', ['invoice', 'bill']);
        $this->assertGreaterThanOrEqual(80, $score);
    }

    public function test_score_empty_returns_0(): void
    {
        $this->assertSame(0, NodeNameMatcher::score('', 'invoice'));
        $this->assertSame(0, NodeNameMatcher::score('invoice', ''));
    }

    // ──────────────────────────────────────────────
    //  matchesClass()
    // ──────────────────────────────────────────────

    public function test_matches_class_fqcn(): void
    {
        $this->assertTrue(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'invoice'));
        $this->assertTrue(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'invoices'));
    }

    public function test_matches_class_no_match(): void
    {
        $this->assertFalse(NodeNameMatcher::matchesClass('App\\Models\\Invoice', 'email'));
    }

    public function test_matches_class_simple_name(): void
    {
        $this->assertTrue(NodeNameMatcher::matchesClass('Invoice', 'invoice'));
    }
}
