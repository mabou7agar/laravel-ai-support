<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Generated;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationIntent;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationPresenter;
use LaravelAIEngine\Services\Agent\AiNative\AiNativeConfirmationPreviewService;
use LaravelAIEngine\Services\Agent\IntentSignalService;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Localization\LocaleResourceService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * Self-contained coverage for the pure-unit collaborators of the
 * Confirmations / approvals subsystem:
 *   - AiNativeConfirmationPreviewService (computed-totals fallback + config knobs)
 *   - AiNativeConfirmationPresenter (message augmentation + summary formatting)
 *   - AiNativeConfirmationIntent (approval lexicon / regex / continuation branches)
 *
 * These services contain no DB or LLM dependencies, so they are exercised
 * directly with anonymous AgentTool fixtures and config overrides. No real
 * AI engine / network calls are made anywhere in this file.
 *
 * The handler-level (AiNativePendingConfirmationHandler) and persisted
 * AgentRunApprovalService scenarios are intentionally NOT reproduced here:
 * the handler requires a deep web of ~12 collaborators that the production
 * AiNativeRuntime wires together, and the persisted approval lifecycle is
 * already covered end-to-end by tests/Feature/AgentRunApprovalLifecycleTest.php
 * (requestStepApproval precedence, approveStep/rejectStep, expiry guard).
 * Duplicating those here against a hand-mocked graph would be brittle and add
 * no real coverage, so they are deferred to that existing feature suite.
 */
class ConfirmationsFlowTest extends UnitTestCase
{
    // ------------------------------------------------------------------
    // Test fixtures
    // ------------------------------------------------------------------

    private function context(): UnifiedActionContext
    {
        return new UnifiedActionContext(sessionId: 'sess-confirmations');
    }

    /**
     * Build an anonymous AgentTool whose previewConfirmation hook and
     * confirmation message are configurable per-test.
     */
    private function tool(
        string $name = 'create_invoice',
        ?ActionResult $preview = null,
        ?string $confirmationMessage = null
    ): AgentTool {
        return new class($name, $preview, $confirmationMessage) extends AgentTool {
            public function __construct(
                private string $toolName,
                private ?ActionResult $previewResult,
                private ?string $confirmationMessage
            ) {}

            public function getName(): string
            {
                return $this->toolName;
            }

            public function getDescription(): string
            {
                return 'fixture tool';
            }

            public function getParameters(): array
            {
                return [];
            }

            public function execute(array $parameters, UnifiedActionContext $context): ActionResult
            {
                return ActionResult::success('executed');
            }

            public function previewConfirmation(array $parameters, UnifiedActionContext $context): ?ActionResult
            {
                return $this->previewResult;
            }

            public function getConfirmationMessage(): ?string
            {
                return $this->confirmationMessage;
            }

            public function requiresConfirmation(): bool
            {
                return true;
            }
        };
    }

    // ==================================================================
    // PreviewService: computed-totals fallback (previewConfirmation null)
    // Scenario: "PreviewService isolated: previewConfirmation null falls back
    //            to computed totals on raw arguments"
    // ==================================================================

    public function test_preview_null_falls_back_to_computed_totals_on_raw_arguments(): void
    {
        $service = new AiNativeConfirmationPreviewService();
        $tool = $this->tool(preview: null);

        $arguments = [
            'customer' => 'ACME',
            'line_items' => [
                ['name' => 'Widget', 'quantity' => 2, 'unit_price' => 5],
                ['name' => 'Gadget', 'quantity' => 3, 'unit_price' => 2.5],
            ],
        ];

        $out = $service->preview($tool, $arguments, $this->context());

        // arguments returned unchanged, result is the null from previewConfirmation
        $this->assertSame($arguments, $out['arguments']);
        $this->assertNull($out['result']);

        $summary = $out['summary'];
        // Each row gets a computed line_total.
        $this->assertSame(10, $summary['line_items'][0]['line_total']); // 2 * 5 -> int
        $this->assertSame(7.5, $summary['line_items'][1]['line_total']); // 3 * 2.5 -> float
        // subtotal/total injected because absent (10 + 7.5 = 17.5).
        $this->assertSame(17.5, $summary['subtotal']);
        $this->assertSame(17.5, $summary['total']);
    }

    // ==================================================================
    // PreviewService: config knobs + null-returning edge rows
    // Scenario: "PreviewService computed-totals config knobs + null-returning
    //            edge rows"
    // ==================================================================

    public function test_preview_computed_totals_disabled_is_a_noop(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.enabled', false);

        $service = new AiNativeConfirmationPreviewService();
        $arguments = [
            'line_items' => [
                ['quantity' => 2, 'unit_price' => 5],
            ],
        ];

        $out = $service->preview($this->tool(preview: null), $arguments, $this->context());

        // Disabled: summary identical to arguments, no totals added.
        $this->assertSame($arguments, $out['summary']);
        $this->assertArrayNotHasKey('subtotal', $out['summary']);
        $this->assertArrayNotHasKey('total', $out['summary']);
    }

    public function test_preview_custom_field_config_routes_totals_to_custom_keys(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.quantity_fields', ['count']);
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.unit_amount_fields', ['cost']);
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.line_total_field', 'row_total');
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.subtotal_field', 'sub');
        config()->set('ai-agent.ai_native.confirmation_summary.computed_totals.total_field', 'grand');

        $service = new AiNativeConfirmationPreviewService();
        $arguments = [
            'rows' => [
                ['count' => 4, 'cost' => 3], // 12
            ],
        ];

        $out = $service->preview($this->tool(preview: null), $arguments, $this->context());

        $this->assertSame(12, $out['summary']['rows'][0]['row_total']);
        $this->assertSame(12, $out['summary']['sub']);
        $this->assertSame(12, $out['summary']['grand']);
        // Default keys must NOT be used.
        $this->assertArrayNotHasKey('line_total', $out['summary']['rows'][0]);
        $this->assertArrayNotHasKey('subtotal', $out['summary']);
        $this->assertArrayNotHasKey('total', $out['summary']);
    }

    public function test_preview_non_numeric_or_non_array_rows_yield_null_and_list_untouched(): void
    {
        $service = new AiNativeConfirmationPreviewService();

        // Mixed non-array row -> computedListTotals returns null early.
        $arguments = [
            'items' => [
                'just-a-string',
                ['name' => 'no totals here'], // no quantity/unit_price -> lineTotal null
            ],
        ];

        $out = $service->preview($this->tool(preview: null), $arguments, $this->context());

        // List returned verbatim, no subtotal/total appended.
        $this->assertSame($arguments['items'], $out['summary']['items']);
        $this->assertArrayNotHasKey('subtotal', $out['summary']);
        $this->assertArrayNotHasKey('total', $out['summary']);
    }

    public function test_preview_preexisting_subtotal_and_total_not_overwritten(): void
    {
        $service = new AiNativeConfirmationPreviewService();
        $arguments = [
            'line_items' => [
                ['quantity' => 2, 'unit_price' => 5, 'line_total' => 999], // pre-existing line_total kept
            ],
            'subtotal' => 1,
            'total' => 2,
        ];

        $out = $service->preview($this->tool(preview: null), $arguments, $this->context());

        $this->assertSame(999, $out['summary']['line_items'][0]['line_total']);
        $this->assertSame(1, $out['summary']['subtotal']);
        $this->assertSame(2, $out['summary']['total']);
    }

    public function test_preview_unsuccessful_result_keeps_raw_arguments_and_returns_result(): void
    {
        // previewConfirmation returns an unsuccessful ActionResult -> fallback branch.
        $failed = ActionResult::failure('inventory check failed');
        $service = new AiNativeConfirmationPreviewService();

        $arguments = ['customer' => 'ACME'];
        $out = $service->preview($this->tool(preview: $failed), $arguments, $this->context());

        $this->assertSame($arguments, $out['arguments']);
        $this->assertSame($failed, $out['result']);
        $this->assertFalse($out['result']->success);
    }

    public function test_preview_successful_result_with_draft_payload_takes_over(): void
    {
        // Successful preview with a draft.payload/summary should replace raw args.
        $success = ActionResult::success('previewed', [
            'draft' => [
                'payload' => ['customer' => 'ACME', 'amount' => 500],
                'summary' => ['Customer' => 'ACME', 'Amount' => 500],
            ],
        ]);
        $service = new AiNativeConfirmationPreviewService();

        $out = $service->preview($this->tool(preview: $success), ['raw' => true], $this->context());

        $this->assertSame(['customer' => 'ACME', 'amount' => 500], $out['arguments']);
        $this->assertSame(['Customer' => 'ACME', 'Amount' => 500], $out['summary']);
        $this->assertSame($success, $out['result']);
    }

    // ==================================================================
    // Presenter: confirmation message override + augmentation heuristic
    // Scenario: "Presenter isolated: tool getConfirmationMessage override and
    //            message-augmentation heuristic"
    // ==================================================================

    public function test_presenter_tool_confirmation_message_wins(): void
    {
        $presenter = new AiNativeConfirmationPresenter();
        $tool = $this->tool(confirmationMessage: 'Custom tool prompt.');

        $message = $presenter->confirmationMessage($tool, 'create_invoice', [], 'runtime message goes here');

        $this->assertStringStartsWith('Custom tool prompt.', $message);
        $this->assertStringNotContainsString('runtime message goes here', $message);
    }

    public function test_presenter_augments_progress_message_without_question_or_confirm(): void
    {
        $presenter = new AiNativeConfirmationPresenter();

        // No tool override; message lacks '?' and 'confirm' -> default used with '_' -> ' '.
        $message = $presenter->confirmationMessage(null, 'create_invoice', [], 'Working on it');

        $this->assertStringContainsString('Please review before I run create invoice.', $message);
        $this->assertStringNotContainsString('Working on it', $message);
    }

    public function test_presenter_keeps_message_that_is_a_question_or_mentions_confirm(): void
    {
        $presenter = new AiNativeConfirmationPresenter();

        $asQuestion = $presenter->confirmationMessage(null, 'create_invoice', [], 'Should I proceed?');
        $this->assertStringStartsWith('Should I proceed?', $asQuestion);

        $mentionsConfirm = $presenter->confirmationMessage(null, 'create_invoice', [], 'Please confirm this write');
        $this->assertStringStartsWith('Please confirm this write', $mentionsConfirm);
    }

    public function test_presenter_empty_message_falls_back_to_default(): void
    {
        $presenter = new AiNativeConfirmationPresenter();

        $message = $presenter->confirmationMessage(null, 'send_email', [], '');

        $this->assertStringContainsString('Please review before I run send email.', $message);
    }

    public function test_presenter_assembles_instruction_with_blank_line_separators(): void
    {
        $presenter = new AiNativeConfirmationPresenter();

        $message = $presenter->confirmationMessage(null, 'create_invoice', ['customer_name' => 'ACME'], 'Should I proceed?');

        // message \n\n summary \n\n instruction
        $this->assertStringContainsString("\n\n", $message);
        $this->assertStringContainsString('Choose Confirm to continue, or Change to edit before execution.', $message);
        $this->assertStringContainsString('Summary:', $message);
    }

    // ==================================================================
    // Presenter: summary formatting knobs
    // Scenario: "Presenter summary truncation, hidden-field fnmatch,
    //            *_name label stripping, summary disabled"
    // ==================================================================

    public function test_presenter_summary_disabled_yields_no_summary_block(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.enabled', false);

        $presenter = new AiNativeConfirmationPresenter();
        $message = $presenter->confirmationMessage(null, 'create_invoice', ['customer' => 'ACME'], 'Should I proceed?');

        $this->assertStringNotContainsString('Summary:', $message);
        $this->assertStringNotContainsString('ACME', $message);
    }

    public function test_presenter_summary_name_label_stripping_and_headline(): void
    {
        $presenter = new AiNativeConfirmationPresenter();

        $message = $presenter->confirmationMessage(null, 'create_invoice', ['customer_name' => 'ACME'], 'Should I proceed?');

        // 'customer_name' -> label 'Customer' (name suffix stripped, headline applied).
        $this->assertMatchesRegularExpression('/Customer:\s*ACME/', $message);
        $this->assertStringNotContainsString('Customer Name:', $message);
    }

    public function test_presenter_summary_max_depth_truncates_with_ellipsis(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.max_depth', 1);

        $presenter = new AiNativeConfirmationPresenter();
        $params = ['outer' => ['inner' => ['deepest' => 'value']]];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('...', $message);
        $this->assertStringNotContainsString('value', $message);
    }

    public function test_presenter_summary_max_items_truncates_list_with_trailing_marker(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.max_items', 2);

        $presenter = new AiNativeConfirmationPresenter();
        $params = ['tags' => ['a', 'b', 'c', 'd']];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('- ...', $message);
    }

    public function test_presenter_summary_long_scalar_truncated_to_max_value_length(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.max_value_length', 20);

        $presenter = new AiNativeConfirmationPresenter();
        $long = str_repeat('x', 50);
        $params = ['note' => $long];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        // max(20, 20) = 20; truncated to 17 chars + '...'
        $this->assertStringContainsString(str_repeat('x', 17).'...', $message);
        $this->assertStringNotContainsString($long, $message);
    }

    public function test_presenter_summary_hidden_field_fnmatch_drops_matching_path(): void
    {
        config()->set('ai-agent.ai_native.confirmation_summary.hidden_fields', ['meta.*']);

        $presenter = new AiNativeConfirmationPresenter();
        $params = [
            'customer' => 'ACME',
            'meta' => ['secret_thing' => 'hidden-value'],
        ];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('ACME', $message);
        // The 'meta.*' pattern drops the nested field's path segments.
        $this->assertStringNotContainsString('hidden-value', $message);
    }

    public function test_presenter_summary_default_hidden_id_fields_omitted(): void
    {
        // Default config hides 'id' and '*_id'.
        $presenter = new AiNativeConfirmationPresenter();
        $params = ['id' => 42, 'customer_id' => 7, 'customer' => 'ACME'];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('ACME', $message);
        $this->assertStringNotContainsString('42', $message);
        $this->assertStringNotContainsString(': 7', $message);
    }

    public function test_presenter_summary_redacted_field_substring_match(): void
    {
        // Default redacted_fields includes 'token' (substring match).
        $presenter = new AiNativeConfirmationPresenter();
        $params = ['api_token' => 'super-secret', 'customer' => 'ACME'];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('[redacted]', $message);
        $this->assertStringNotContainsString('super-secret', $message);
    }

    public function test_presenter_summary_empty_values_hidden(): void
    {
        $presenter = new AiNativeConfirmationPresenter();
        $params = ['customer' => 'ACME', 'note' => '', 'extra' => null, 'tags' => []];

        $message = $presenter->confirmationMessage(null, 'create_invoice', $params, 'Should I proceed?');

        $this->assertStringContainsString('ACME', $message);
        $this->assertStringNotContainsString('Note:', $message);
        $this->assertStringNotContainsString('Extra:', $message);
        $this->assertStringNotContainsString('Tags:', $message);
    }

    // ==================================================================
    // ConfirmationIntent: lexicon / regex / continuation branches
    // Scenario: "ConfirmationIntent lexicon and regex branches + wildcard
    //            continuation term"
    // ==================================================================

    private function intentWithSignals(bool $affirmative): AiNativeConfirmationIntent
    {
        $signals = Mockery::mock(IntentSignalService::class);
        $signals->shouldReceive('isAffirmative')->andReturn($affirmative);

        // Locale stub that never matches, so we isolate the regex/continuation branches.
        $locale = Mockery::mock(LocaleResourceService::class);
        $locale->shouldReceive('isLexiconMatch')->andReturn(false);

        return new AiNativeConfirmationIntent($signals, $locale);
    }

    public function test_intent_single_word_approvals_match_regex_branch(): void
    {
        // isAffirmative false + locale misses -> regex branch must catch these.
        $intent = $this->intentWithSignals(false);

        $this->assertTrue($intent->isApproval('proceed'));
        $this->assertTrue($intent->isApproval('approved'));
        $this->assertTrue($intent->isApproval('okay'));
        $this->assertTrue($intent->isApproval('confirm'));
    }

    public function test_intent_affirmative_short_circuits_first(): void
    {
        // isAffirmative true -> returns true regardless of locale/regex.
        $intent = $this->intentWithSignals(true);

        $this->assertTrue($intent->isApproval('sounds good to me'));
    }

    public function test_intent_locale_lexicon_branch_matches_when_regex_and_affirmative_miss(): void
    {
        $signals = Mockery::mock(IntentSignalService::class);
        $signals->shouldReceive('isAffirmative')->andReturn(false);

        $locale = Mockery::mock(LocaleResourceService::class);
        // Matches via intent.confirm only.
        $locale->shouldReceive('isLexiconMatch')
            ->with('vamos a ello', 'intent.confirm')
            ->andReturn(true);
        $locale->shouldReceive('isLexiconMatch')->andReturn(false);

        $intent = new AiNativeConfirmationIntent($signals, $locale);

        $this->assertTrue($intent->isApproval('vamos a ello'));
    }

    public function test_intent_wildcard_continuation_term_matches_full_string_pattern(): void
    {
        config()->set('ai-agent.skills.continuation_terms', ['create .*']);

        $intent = $this->intentWithSignals(false);

        $this->assertTrue($intent->isApproval('create the invoice'));
    }

    public function test_intent_returns_false_for_non_approval_non_wildcard_phrase(): void
    {
        // No wildcard match for this phrase; regex/affirmative/locale all miss.
        config()->set('ai-agent.skills.continuation_terms', ['create .*']);

        $intent = $this->intentWithSignals(false);

        $this->assertFalse($intent->isApproval('continue with products'));
    }
}
