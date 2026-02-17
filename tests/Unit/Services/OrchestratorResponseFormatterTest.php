<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\OrchestratorResponseFormatter;
use Orchestra\Testbench\TestCase;

class OrchestratorResponseFormatterTest extends TestCase
{
    protected OrchestratorResponseFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new OrchestratorResponseFormatter();
    }

    // ──────────────────────────────────────────────
    //  formatHistory
    // ──────────────────────────────────────────────

    public function test_format_history_new_conversation(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $context->conversationHistory = [];

        $this->assertSame('(New conversation)', $this->formatter->formatHistory($context));
    }

    public function test_format_history_single_message_is_new(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'hello'],
        ];

        $this->assertSame('(New conversation)', $this->formatter->formatHistory($context));
    }

    public function test_format_history_shows_last_5_messages(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $context->conversationHistory = [];
        for ($i = 1; $i <= 8; $i++) {
            $context->conversationHistory[] = ['role' => $i % 2 === 1 ? 'user' : 'assistant', 'content' => "msg {$i}"];
        }

        $result = $this->formatter->formatHistory($context);

        // Should contain messages 4-8 (last 5)
        $this->assertStringContainsString('msg 4', $result);
        $this->assertStringContainsString('msg 8', $result);
        $this->assertStringNotContainsString('msg 3', $result);
    }

    public function test_format_history_truncates_long_messages(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $longContent = str_repeat('x', 500);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'short'],
            ['role' => 'assistant', 'content' => $longContent],
        ];

        $result = $this->formatter->formatHistory($context);

        // Should be truncated to 300 chars (no numbered options)
        $this->assertLessThan(strlen($longContent), strlen($result));
    }

    public function test_format_history_preserves_numbered_options(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $numberedContent = "Choose an option:\n1. First option\n2. Second option\n3. Third option";
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list options'],
            ['role' => 'assistant', 'content' => $numberedContent],
        ];

        $result = $this->formatter->formatHistory($context);

        $this->assertStringContainsString('1. First option', $result);
        $this->assertStringContainsString('3. Third option', $result);
    }

    // ──────────────────────────────────────────────
    //  formatSelectedEntityContext
    // ──────────────────────────────────────────────

    public function test_format_selected_entity_none(): void
    {
        $this->assertSame('(none)', $this->formatter->formatSelectedEntityContext(null));
    }

    public function test_format_selected_entity_with_data(): void
    {
        $entity = ['id' => 42, 'name' => 'Test Invoice', 'amount' => 100];
        $result = $this->formatter->formatSelectedEntityContext($entity);

        $this->assertStringContainsString('"id": 42', $result);
        $this->assertStringContainsString('Test Invoice', $result);
    }

    // ──────────────────────────────────────────────
    //  formatEntityMetadata
    // ──────────────────────────────────────────────

    public function test_format_entity_metadata_from_assistant_message(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'list invoices'],
            ['role' => 'assistant', 'content' => 'Found 3 invoices', 'metadata' => [
                'entity_ids' => [1, 2, 3],
                'entity_type' => 'invoice',
            ]],
        ];

        $result = $this->formatter->formatEntityMetadata($context);

        $this->assertStringContainsString('ENTITY CONTEXT', $result);
        $this->assertStringContainsString('invoice', $result);
        $this->assertStringContainsString('[1,2,3]', $result);
    }

    public function test_format_entity_metadata_empty_when_no_entities(): void
    {
        $context = new UnifiedActionContext(sessionId: 's1', userId: 1);
        $context->conversationHistory = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi there'],
        ];

        $this->assertSame('', $this->formatter->formatEntityMetadata($context));
    }

    // ──────────────────────────────────────────────
    //  formatPausedSessions
    // ──────────────────────────────────────────────

    public function test_format_paused_sessions_none(): void
    {
        $this->assertSame('None', $this->formatter->formatPausedSessions([]));
    }

    public function test_format_paused_sessions_with_data(): void
    {
        $sessions = [
            ['config_name' => 'InvoiceCollector'],
            ['config_name' => 'EmailCollector'],
        ];

        $result = $this->formatter->formatPausedSessions($sessions);

        $this->assertStringContainsString('InvoiceCollector', $result);
        $this->assertStringContainsString('EmailCollector', $result);
    }

    // ──────────────────────────────────────────────
    //  formatCollectors
    // ──────────────────────────────────────────────

    public function test_format_collectors_empty(): void
    {
        $this->assertStringContainsString('No collectors', $this->formatter->formatCollectors([]));
    }

    public function test_format_collectors_with_data(): void
    {
        $collectors = [
            ['name' => 'invoice_collector', 'goal' => 'Collect invoices', 'description' => 'Step-by-step invoice creation'],
            ['name' => 'email_sender', 'goal' => 'Send emails', 'description' => 'Compose email', 'node' => 'email-node'],
        ];

        $result = $this->formatter->formatCollectors($collectors);

        $this->assertStringContainsString('invoice_collector', $result);
        $this->assertStringContainsString('email_sender', $result);
        $this->assertStringContainsString('email-node', $result);
    }

    // ──────────────────────────────────────────────
    //  formatTools
    // ──────────────────────────────────────────────

    public function test_format_tools_empty(): void
    {
        $this->assertStringContainsString('No tools', $this->formatter->formatTools([]));
    }

    public function test_format_tools_with_data(): void
    {
        $tools = [
            ['name' => 'create_invoice', 'model' => 'Invoice', 'description' => 'Create a new invoice'],
            ['name' => 'list_customers', 'model' => 'Customer', 'description' => 'List all customers'],
        ];

        $result = $this->formatter->formatTools($tools);

        $this->assertStringContainsString('create_invoice (Invoice)', $result);
        $this->assertStringContainsString('list_customers (Customer)', $result);
    }

    // ──────────────────────────────────────────────
    //  formatNodes
    // ──────────────────────────────────────────────

    public function test_format_nodes_empty(): void
    {
        $this->assertStringContainsString('No nodes', $this->formatter->formatNodes([]));
    }

    public function test_format_nodes_with_data(): void
    {
        $nodes = [
            ['slug' => 'invoicing-node', 'description' => 'Handles invoices', 'domains' => ['billing', 'payments']],
            ['slug' => 'email-node', 'description' => 'Handles email', 'domains' => ['communication']],
        ];

        $result = $this->formatter->formatNodes($nodes);

        $this->assertStringContainsString('invoicing-node', $result);
        $this->assertStringContainsString('billing, payments', $result);
        $this->assertStringContainsString('email-node', $result);
    }
}
