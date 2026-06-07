<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\AnalyzeFileTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Services\FileAnalysisService;
use LaravelAIEngine\Services\Media\DocumentService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

/**
 * analyze_file: generic, entity-agnostic "extract a stored upload -> suggest the create
 * action it implies", with a sandboxed file path (no arbitrary reads) and registry-validated
 * suggestions. Deterministic (text/csv extraction + config keyword patterns, no LLM).
 */
class AnalyzeFileToolTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/afa_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);

        config()->set('ai-engine.file_analysis.base_path', $this->dir);
        config()->set('ai-engine.file_analysis.validate_actions', true);
        config()->set('ai-engine.file_analysis.keyword_suggestions', [
            ['pattern' => '/\binvoice\b/i', 'action_id' => 'create_invoice', 'action_label' => 'Create an invoice from this file', 'confidence' => 80],
            ['pattern' => '/\bcustomer\b/i', 'action_id' => 'create_customer', 'action_label' => 'Create a customer from this file', 'confidence' => 70],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->dir . '/' . $name, $content);

        return $name;
    }

    private function tool(array $registeredActions = ['create_invoice', 'create_customer']): AnalyzeFileTool
    {
        $registry = new ToolRegistry();
        foreach ($registeredActions as $name) {
            $registry->register($name, new FakeCreateActionTool($name));
        }

        $fileService = new FileAnalysisService(
            Mockery::mock(ChatService::class),
            Mockery::mock(ConversationTranscriptService::class),
            app(DocumentService::class)
        );

        return new AnalyzeFileTool($fileService, $registry);
    }

    private function analyze(string $path, array $registered = ['create_invoice', 'create_customer']): ActionResult
    {
        return $this->tool($registered)->execute(['path' => $path], new UnifiedActionContext('afa'));
    }

    public function test_extracts_text_and_suggests_create_invoice(): void
    {
        $this->write('inv.txt', "INVOICE #INV-9\nBill to: Acme Corp\nTotal: 1200.00");

        $r = $this->analyze('inv.txt');

        $this->assertTrue($r->success);
        $this->assertTrue($r->data['analyzed']);
        $ids = array_column($r->data['suggestions'], 'action_id');
        $this->assertContains('create_invoice', $ids);
    }

    public function test_is_generic_suggests_create_customer_for_a_customer_file(): void
    {
        $this->write('people.csv', "name,email\nAcme,The customer here\n");

        $r = $this->analyze('people.csv');

        $ids = array_column($r->data['suggestions'], 'action_id');
        $this->assertContains('create_customer', $ids);
        $this->assertNotContains('create_invoice', $ids);
    }

    public function test_suggestions_filtered_to_registered_actions(): void
    {
        $this->write('inv.txt', 'this is an invoice and a customer');

        // Only create_invoice is registered -> create_customer suggestion is dropped.
        $r = $this->analyze('inv.txt', ['create_invoice']);

        $ids = array_column($r->data['suggestions'], 'action_id');
        $this->assertContains('create_invoice', $ids);
        $this->assertNotContains('create_customer', $ids);
    }

    public function test_no_keyword_match_returns_no_suggestions(): void
    {
        $this->write('notes.txt', 'just some random meeting notes about the weather');

        $r = $this->analyze('notes.txt');

        $this->assertTrue($r->success);
        $this->assertSame([], $r->data['suggestions']);
    }

    public function test_rejects_path_outside_sandbox(): void
    {
        $r = $this->analyze('/etc/hosts');
        $this->assertFalse($r->success);
        $this->assertFalse($r->data['analyzed'] ?? true);
    }

    public function test_rejects_path_traversal(): void
    {
        $r = $this->analyze('../../../../etc/hosts');
        $this->assertFalse($r->success);
    }

    public function test_rejects_disallowed_extension(): void
    {
        $this->write('script.php', '<?php echo "x"; invoice');
        $r = $this->analyze('script.php');
        $this->assertFalse($r->success);
        $this->assertStringContainsStringIgnoringCase('not supported', (string) ($r->error ?? $r->message));
    }

    public function test_rejects_oversize_file(): void
    {
        config()->set('ai-engine.file_analysis.max_bytes', 10);
        $this->write('big.txt', str_repeat('invoice ', 100));
        $r = $this->analyze('big.txt');
        $this->assertFalse($r->success);
        $this->assertStringContainsStringIgnoringCase('too large', (string) ($r->error ?? $r->message));
    }

    public function test_missing_file_fails_closed(): void
    {
        $r = $this->analyze('does-not-exist.txt');
        $this->assertFalse($r->success);
    }
}

class FakeCreateActionTool extends AgentTool
{
    public function __construct(private string $name)
    {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Fake create tool.';
    }

    public function getParameters(): array
    {
        return [];
    }

    public function execute(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('ok');
    }
}
