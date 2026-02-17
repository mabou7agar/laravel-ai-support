<?php

namespace LaravelAIEngine\Services\Agent;

use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRoutingDigestService;

/**
 * Determines whether a routed session should continue on the current node,
 * switch to a different node, or fall back to local handling.
 *
 * Returns a 3-way decision:
 *  - CONTINUE          — message is related to the current node, keep forwarding
 *  - RE_ROUTE:<slug>   — message belongs to a different remote node
 *  - LOCAL             — message should be handled locally (no remote node fits)
 *
 * The AI prompt includes ALL available nodes (via the routing digest) so it
 * can make an informed decision instead of a blind binary choice.
 */
class RoutedSessionPolicyService
{
    public const DECISION_CONTINUE = 'CONTINUE';
    public const DECISION_LOCAL = 'LOCAL';
    public const DECISION_RE_ROUTE = 'RE_ROUTE'; // followed by :node_slug

    public function __construct(
        protected AIEngineService $ai,
        protected NodeRegistryService $nodeRegistry,
        protected array $settings = []
    ) {
    }

    // ──────────────────────────────────────────────
    //  Public API
    // ──────────────────────────────────────────────

    /**
     * Backward-compatible: returns true if session should continue on the same node.
     */
    public function shouldContinue(string $message, UnifiedActionContext $context): bool
    {
        $decision = $this->evaluate($message, $context);
        return $decision['action'] === self::DECISION_CONTINUE;
    }

    /**
     * Full 3-way evaluation.
     *
     * @return array{action: string, node_slug: string|null, reason: string}
     */
    public function evaluate(string $message, UnifiedActionContext $context): array
    {
        $nodeInfo = $context->get('routed_to_node');
        $nodeSlug = $nodeInfo['node_slug'] ?? null;

        if (!$nodeSlug) {
            return $this->decision(self::DECISION_LOCAL, null, 'No active routed node');
        }

        $node = $this->nodeRegistry->getNode($nodeSlug);
        if (!$node) {
            return $this->decision(self::DECISION_LOCAL, null, 'Active node not found');
        }

        // Fast-path: short follow-up messages ("1", "yes", "ok", "next") are almost
        // always continuations of the current conversation.
        if ($this->isLikelyFollowUp($message, $context)) {
            return $this->decision(self::DECISION_CONTINUE, $nodeSlug, 'Short follow-up detected');
        }

        // Build context for AI
        $historyText = $this->buildHistoryText($context);
        $currentNodeSummary = $this->buildNodeSummary($node);
        $otherNodesSummary = $this->buildOtherNodesSummary($node);

        $prompt = $this->buildEvaluationPrompt(
            $message,
            $node->name ?? $nodeSlug,
            $nodeSlug,
            $currentNodeSummary,
            $otherNodesSummary,
            $historyText
        );

        try {
            $request = new AIRequest(
                prompt: $prompt,
                engine: $this->resolveEngine(),
                model: $this->resolveModel(),
                maxTokens: 30,
                temperature: 0.0
            );

            $aiResponse = $this->ai->generate($request);
            $raw = trim($aiResponse->getContent());

            $parsed = $this->parseAIResponse($raw, $nodeSlug);

            Log::channel('ai-engine')->info('Routed session evaluation', [
                'message' => substr($message, 0, 100),
                'current_node' => $nodeSlug,
                'raw_ai' => $raw,
                'decision' => $parsed['action'],
                'target_node' => $parsed['node_slug'],
            ]);

            return $parsed;

        } catch (\Exception $e) {
            Log::channel('ai-engine')->warning('Routed session evaluation failed', [
                'error' => $e->getMessage(),
                'node' => $nodeSlug,
            ]);

            // On error, default to continuing on the same node (safest)
            return $this->decision(
                $this->fallbackContinueOnAIError() ? self::DECISION_CONTINUE : self::DECISION_LOCAL,
                $this->fallbackContinueOnAIError() ? $nodeSlug : null,
                'AI evaluation failed, using fallback'
            );
        }
    }

    // ──────────────────────────────────────────────
    //  Fast-path: short follow-ups
    // ──────────────────────────────────────────────

    /**
     * Detect messages that are almost certainly follow-ups to the current node's
     * last response: positional references, confirmations, pagination.
     *
     * This avoids an unnecessary AI call for obvious cases like "1", "yes",
     * "next page", "show details", "tell me more".
     */
    protected function isLikelyFollowUp(string $message, UnifiedActionContext $context): bool
    {
        $msg = strtolower(trim($message));

        // Pure number (positional reference like "1", "2", "3")
        if (preg_match('/^\d{1,3}$/', $msg)) {
            return true;
        }

        // Short confirmations / pagination
        $followUpPatterns = [
            '/^(yes|no|ok|okay|sure|yep|nope|cancel|done|thanks|thank you)$/i',
            '/^(next|previous|prev|more|back|first|last|show|details|expand)$/i',
            '/^(next page|prev page|show more|tell me more|go back)$/i',
            '/^(the )?(first|second|third|fourth|fifth|last|[0-9]+(?:st|nd|rd|th)?) ?(one)?$/i',
        ];

        foreach ($followUpPatterns as $pattern) {
            if (preg_match($pattern, $msg)) {
                return true;
            }
        }

        return false;
    }

    // ──────────────────────────────────────────────
    //  Prompt building
    // ──────────────────────────────────────────────

    protected function buildEvaluationPrompt(
        string $message,
        string $currentNodeName,
        string $currentNodeSlug,
        string $currentNodeSummary,
        string $otherNodesSummary,
        string $historyText
    ): string {
        $prompt = <<<PROMPT
You are a routing evaluator. A user is in an active conversation routed to a specific node. Determine what should happen with their new message.

ACTIVE NODE: {$currentNodeName} ({$currentNodeSlug})
{$currentNodeSummary}

RECENT CONVERSATION:
{$historyText}

NEW MESSAGE: "{$message}"
PROMPT;

        if ($otherNodesSummary !== '') {
            $prompt .= <<<PROMPT


OTHER AVAILABLE NODES:
{$otherNodesSummary}
PROMPT;
        }

        $prompt .= <<<PROMPT


DECISION RULES:
1. If the message is a follow-up to the conversation (selecting items, asking details, pagination, confirmation) → CONTINUE
2. If the message asks about a topic that the ACTIVE NODE handles → CONTINUE
3. If the message asks about a topic that a DIFFERENT node handles → RE_ROUTE:<node_slug>
4. If the message is about a topic no remote node handles → LOCAL

CRITICAL:
- Short messages like numbers, "yes", "next", "show details" are ALWAYS follow-ups → CONTINUE
- "list X" or "show X" where X matches the active node's domain → CONTINUE
- "list X" or "show X" where X matches a DIFFERENT node's domain → RE_ROUTE:<that_node_slug>
- When uncertain, prefer CONTINUE over LOCAL

Respond with EXACTLY one of:
- CONTINUE
- RE_ROUTE:<node_slug>
- LOCAL
PROMPT;

        return $prompt;
    }

    protected function buildNodeSummary(AINode $node): string
    {
        $collections = $this->extractCollectionNames($node);
        $domains = $node->domains ?? [];

        $parts = [];
        if (!empty($collections)) {
            $parts[] = 'Handles: ' . implode(', ', $collections);
        }
        if (!empty($domains)) {
            $parts[] = 'Domains: ' . implode(', ', array_slice($domains, 0, 5));
        }

        return !empty($parts) ? implode('. ', $parts) . '.' : 'General operations.';
    }

    protected function buildOtherNodesSummary(AINode $excludeNode): string
    {
        // Try to use the digest service for compact summaries
        try {
            $digestService = app(NodeRoutingDigestService::class);
        } catch (\Throwable $e) {
            $digestService = null;
        }

        $otherNodes = $this->nodeRegistry->getActiveNodes()->filter(
            fn (AINode $n) => $n->id !== $excludeNode->id && $n->type === 'child'
        );

        if ($otherNodes->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($otherNodes as $node) {
            if ($digestService) {
                try {
                    $lines[] = $digestService->getNodeDigest($node);
                    continue;
                } catch (\Throwable $e) {
                    // Fall through to manual summary
                }
            }

            $collections = $this->extractCollectionNames($node);
            $name = $node->name ?: $node->slug;
            $handles = !empty($collections) ? implode(', ', $collections) : 'general';
            $lines[] = "- {$name} ({$node->slug}): handles {$handles}";
        }

        return implode("\n", $lines);
    }

    protected function buildHistoryText(UnifiedActionContext $context): string
    {
        $historyWindow = max(1, (int) ($this->settings['history_window'] ?? 4));
        $conversationHistory = $context->conversationHistory ?? [];
        $recentMessages = array_slice($conversationHistory, -1 * $historyWindow);

        $lines = [];
        foreach ($recentMessages as $msg) {
            $role = $msg['role'] ?? 'unknown';
            $content = $msg['content'] ?? '';
            // Truncate long messages to save tokens
            if (strlen($content) > 200) {
                $content = substr($content, 0, 200) . '...';
            }
            $lines[] = "{$role}: {$content}";
        }

        return implode("\n", $lines);
    }

    // ──────────────────────────────────────────────
    //  AI response parsing
    // ──────────────────────────────────────────────

    protected function parseAIResponse(string $raw, string $currentNodeSlug): array
    {
        $upper = strtoupper(trim($raw));

        // CONTINUE
        if (str_contains($upper, 'CONTINUE')) {
            return $this->decision(self::DECISION_CONTINUE, $currentNodeSlug, 'AI: continue on current node');
        }

        // RE_ROUTE:<slug>
        if (preg_match('/RE_ROUTE\s*[:=]\s*(\S+)/i', $raw, $m)) {
            $targetSlug = strtolower(trim($m[1]));

            // Validate the target node exists
            $targetNode = $this->nodeRegistry->getNode($targetSlug);
            if ($targetNode && $targetNode->slug !== $currentNodeSlug) {
                return $this->decision(self::DECISION_RE_ROUTE, $targetSlug, "AI: re-route to {$targetSlug}");
            }

            // Invalid slug — fall back to LOCAL
            Log::channel('ai-engine')->warning('AI suggested re-route to unknown node', [
                'suggested_slug' => $targetSlug,
                'current_node' => $currentNodeSlug,
            ]);
            return $this->decision(self::DECISION_LOCAL, null, 'AI suggested unknown node, falling back to local');
        }

        // LOCAL
        if (str_contains($upper, 'LOCAL')) {
            return $this->decision(self::DECISION_LOCAL, null, 'AI: handle locally');
        }

        // Legacy: RELATED = CONTINUE, DIFFERENT = LOCAL
        if (str_contains($upper, 'RELATED')) {
            return $this->decision(self::DECISION_CONTINUE, $currentNodeSlug, 'AI: related (legacy)');
        }
        if (str_contains($upper, 'DIFFERENT')) {
            return $this->decision(self::DECISION_LOCAL, null, 'AI: different (legacy)');
        }

        // Unparseable — default to CONTINUE (safest)
        Log::channel('ai-engine')->warning('Unparseable routing evaluation response', [
            'raw' => $raw,
            'current_node' => $currentNodeSlug,
        ]);
        return $this->decision(self::DECISION_CONTINUE, $currentNodeSlug, 'Unparseable AI response, defaulting to continue');
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    protected function decision(string $action, ?string $nodeSlug, string $reason): array
    {
        return [
            'action' => $action,
            'node_slug' => $nodeSlug,
            'reason' => $reason,
        ];
    }

    protected function extractCollectionNames(AINode $node): array
    {
        $names = [];
        foreach ($node->collections ?? [] as $collection) {
            if (is_array($collection)) {
                $names[] = $collection['name'] ?? '';
            } else {
                $names[] = strtolower(class_basename($collection));
            }
        }
        return array_values(array_filter($names));
    }

    protected function fallbackContinueOnAIError(): bool
    {
        return (bool) ($this->settings['fallback_continue_on_ai_error'] ?? true);
    }

    protected function resolveEngine(): EngineEnum
    {
        $engine = (string) ($this->settings['engine'] ?? config('ai-engine.default', 'openai'));
        return EngineEnum::from($engine);
    }

    protected function resolveModel(): EntityEnum
    {
        $model = (string) ($this->settings['model'] ?? config('ai-engine.orchestration_model', 'gpt-4o-mini'));
        return EntityEnum::from($model);
    }
}
