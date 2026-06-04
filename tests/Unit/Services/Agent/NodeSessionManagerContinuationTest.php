<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Agent\AgentResponseFinalizer;
use LaravelAIEngine\Services\Agent\NodeSessionManager;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\NodeRouterService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Focused coverage for the LLM context-detection branch of
 * NodeSessionManager::shouldContinueSession().
 *
 * The method has four heuristic short-circuits that run BEFORE any AI call:
 *   1. empty/missing node (routed_to_node slug + getNode)
 *   2. email-query on a non-email node
 *   3. an active remote pending action
 *   4. a message that looks like a direct answer to the previous question
 *
 * Only when NONE of those fire does it reach AIEngineService::generate() to
 * classify the new message as RELATED (keep session) or DIFFERENT (drop).
 *
 * The coverage report flagged this branch as a falsely-green risk: a test could
 * mis-seed a message that silently routes around the LLM via one of the
 * heuristics and still pass. To guard against that, every test here asserts
 * `shouldReceive('generate')->once()` (a STRICT once). If control never reaches
 * the LLM, Mockery fails the test at teardown for an unmet expectation, so the
 * heuristic-bypass is proven rather than assumed.
 */
class NodeSessionManagerContinuationTest extends UnitTestCase
{
    use \LaravelAIEngine\Tests\Concerns\RequiresFederation;

    /**
     * A node whose collections contain no email/mail term, so the
     * email-query short-circuit only fires for genuine email messages.
     */
    private function billingNode(): AINode
    {
        $node = new AINode();
        $node->forceFill([
            'slug' => 'billing',
            'name' => 'Billing',
            'collections' => ['invoice', 'product'],
        ]);

        return $node;
    }

    /**
     * @param AIResponse|\Throwable $result the stubbed generate() outcome
     */
    private function makeManager(AIEngineService $ai): NodeSessionManager
    {
        $registry = Mockery::mock(NodeRegistryService::class);
        $registry->shouldReceive('getNode')
            ->with('billing')
            ->andReturn($this->billingNode());

        return new NodeSessionManager(
            $ai,
            $registry,
            Mockery::mock(NodeRouterService::class),
            Mockery::mock(AgentResponseFinalizer::class)
        );
    }

    /**
     * A context routed to the billing node with NO pending action and NO
     * prior assistant question, so the only path left is the LLM call.
     */
    private function routedContext(): UnifiedActionContext
    {
        $context = new UnifiedActionContext('session-llm', 7);
        $context->set('routed_to_node', ['node_slug' => 'billing']);
        // Empty history => isLikelyAnswerToPreviousQuestion() cannot fire
        // (no previous assistant message to be answering).
        $context->conversationHistory = [];

        return $context;
    }

    /**
     * The message used to reach the LLM. It deliberately:
     *  - is non-empty,
     *  - contains no email/mail/inbox/message word (skips email short-circuit),
     *  - has no pending action seeded,
     *  - with an empty history, cannot be "an answer to a previous question".
     */
    private const LLM_MESSAGE = 'update the product pricing strategy for next quarter';

    public function test_llm_says_related_keeps_session(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        // STRICT once: proves all heuristic short-circuits were bypassed and the
        // LLM was actually reached. A mis-seeded message that routes around the
        // LLM leaves this unmet and fails the test.
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success('RELATED', 'openai', 'gpt-4o-mini'));

        $manager = $this->makeManager($ai);

        $this->assertTrue(
            $manager->shouldContinueSession(self::LLM_MESSAGE, $this->routedContext()),
            'RELATED from the LLM must keep the routed session.'
        );
    }

    public function test_llm_says_different_drops_session(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success('DIFFERENT', 'openai', 'gpt-4o-mini'));

        $manager = $this->makeManager($ai);

        $this->assertFalse(
            $manager->shouldContinueSession(self::LLM_MESSAGE, $this->routedContext()),
            'DIFFERENT from the LLM must drop the routed session.'
        );
    }

    public function test_llm_throws_falls_back_to_continue(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        // Documented behavior (see catch block): an AI failure logs a warning
        // and DEFAULTS TO CONTINUE routing, i.e. returns true.
        $ai->shouldReceive('generate')
            ->once()
            ->andThrow(new \RuntimeException('LLM upstream unavailable'));

        $manager = $this->makeManager($ai);

        $this->assertTrue(
            $manager->shouldContinueSession(self::LLM_MESSAGE, $this->routedContext()),
            'When the AI call throws, the method must gracefully default to continuing the session.'
        );
    }

    /**
     * Extra guard for the decision parsing: the method returns
     * !str_contains($decision, 'DIFFERENT') after uppercasing/trimming, so a
     * lowercase / padded "related" answer must still keep the session.
     */
    public function test_llm_related_answer_is_case_and_whitespace_insensitive(): void
    {
        $ai = Mockery::mock(AIEngineService::class);
        $ai->shouldReceive('generate')
            ->once()
            ->andReturn(AIResponse::success("  related  ", 'openai', 'gpt-4o-mini'));

        $manager = $this->makeManager($ai);

        $this->assertTrue(
            $manager->shouldContinueSession(self::LLM_MESSAGE, $this->routedContext())
        );
    }
}
