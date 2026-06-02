<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Agent;

use LaravelAIEngine\Contracts\ActionFlowHandler;
use LaravelAIEngine\Contracts\AgentRuntimeContract;
use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\AgentResponse;
use LaravelAIEngine\DTOs\AgentRuntimeCapabilities;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\AgentResponseSuggestionService;
use LaravelAIEngine\Services\Agent\AgentSkillRegistry;
use LaravelAIEngine\Services\Agent\ChatResponsePresentationService;
use LaravelAIEngine\Services\Agent\ResponsePointExtractor;
use LaravelAIEngine\Services\Agent\Tools\AgentTool;
use LaravelAIEngine\Services\Agent\Tools\ToolRegistry;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationTranscriptService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

/**
 * END-TO-END "response suggestions" flow.
 *
 * Drives a chat turn through a real ChatService -> real ChatResponsePresentationService
 * -> real AgentResponseSuggestionService, with the AI engine / agent runtime faked so no
 * network calls happen. Asserts the final AIResponse surfaces 'suggestions' in metadata and
 * that response_suggestion_limit is enforced through the whole flow.
 */
class FlowSugSuggestionsE2EFlowTest extends UnitTestCase
{
    /**
     * Build a real ChatService whose presentation/suggestion stack is wired with the
     * supplied (controllable) capability registries. The agent runtime is faked to return
     * the given message so no real AI engine is invoked.
     *
     * @param array<int, array<string, mixed>> $catalogActions
     */
    private function buildService(string $runtimeMessage, array $catalogActions): ChatService
    {
        $skills = Mockery::mock(AgentSkillRegistry::class);
        $skills->shouldReceive('skills')->andReturn([]);

        $tools = new ToolRegistry();

        $actions = Mockery::mock(ActionFlowHandler::class);
        $actions->shouldReceive('catalog')->andReturn([
            'success' => true,
            'actions' => $catalogActions,
        ]);
        $actions->shouldReceive('suggest')->andReturn([
            'success' => true,
            'suggestions' => [],
        ]);

        $suggestionService = new AgentResponseSuggestionService($skills, $tools, $actions);
        $presentation = new ChatResponsePresentationService(new ResponsePointExtractor(), $suggestionService);

        $runtime = new FlowSugFakeRuntime($runtimeMessage);

        // Memory disabled => transcript service is never touched, so the mock needs no expectations.
        return new ChatService(
            Mockery::mock(ConversationTranscriptService::class),
            $runtime,
            $presentation
        );
    }

    public function test_end_to_end_chat_turn_surfaces_suggestions_in_metadata(): void
    {
        $service = $this->buildService(
            runtimeMessage: 'I found the customer record and the line items for this invoice.',
            catalogActions: [[
                'id' => 'flowsug_create_invoice',
                'label' => 'Create invoice',
                'description' => 'Create an invoice for a customer from provided line items.',
                'operation' => 'create',
                'required' => ['customer_id'],
                'parameters' => [],
                'confirmation_required' => true,
            ]]
        );

        $response = $service->processMessage(
            message: 'Please create an invoice for this customer',
            sessionId: 'flowsug-e2e-basic',
            useMemory: false,
            userId: 7,
            extraOptions: [
                'response_suggestions' => true,
            ]
        );

        $this->assertTrue($response->success);

        $suggestions = $response->getMetadata()['suggestions'] ?? null;
        $this->assertIsArray($suggestions);
        $this->assertNotEmpty($suggestions);

        $ids = array_column($suggestions, 'id');
        $this->assertContains('flowsug_create_invoice', $ids);
        $this->assertSame(count($suggestions), $response->getMetadata()['suggestions_count']);
    }

    public function test_end_to_end_flow_respects_response_suggestion_limit(): void
    {
        // Seven distinct catalog actions that all match the message/response haystack.
        $catalogActions = [];
        foreach (range(1, 7) as $i) {
            $catalogActions[] = [
                'id' => "flowsug_invoice_action_{$i}",
                'label' => "Invoice action {$i}",
                'description' => 'Handle an invoice for the customer.',
                'operation' => 'invoice',
                'required' => [],
                'parameters' => [],
                'confirmation_required' => false,
            ];
        }

        $service = $this->buildService(
            runtimeMessage: 'Here is the invoice and customer information you requested.',
            catalogActions: $catalogActions
        );

        $response = $service->processMessage(
            message: 'Show me invoice options for this customer',
            sessionId: 'flowsug-e2e-limit',
            useMemory: false,
            userId: 7,
            extraOptions: [
                'response_suggestions' => true,
                'response_suggestion_limit' => 3,
            ]
        );

        $suggestions = $response->getMetadata()['suggestions'] ?? [];
        $this->assertIsArray($suggestions);

        // More than 3 actions matched, but the limit caps the surfaced suggestions at 3.
        $this->assertCount(3, $suggestions);
        $this->assertSame(3, $response->getMetadata()['suggestions_count']);

        foreach ($suggestions as $suggestion) {
            $this->assertStringStartsWith('flowsug_invoice_action_', (string) $suggestion['id']);
        }
    }

    public function test_end_to_end_flow_omits_suggestions_when_disabled(): void
    {
        $service = $this->buildService(
            runtimeMessage: 'Here is the invoice and customer information you requested.',
            catalogActions: [[
                'id' => 'flowsug_create_invoice',
                'label' => 'Create invoice',
                'description' => 'Create an invoice for a customer.',
                'operation' => 'create',
                'required' => [],
                'parameters' => [],
                'confirmation_required' => false,
            ]]
        );

        $response = $service->processMessage(
            message: 'Please create an invoice for this customer',
            sessionId: 'flowsug-e2e-disabled',
            useMemory: false,
            userId: 7,
            extraOptions: [
                'response_suggestions' => false,
            ]
        );

        $this->assertArrayNotHasKey('suggestions', $response->getMetadata());
        $this->assertArrayNotHasKey('suggestions_count', $response->getMetadata());
    }
}

/**
 * Minimal in-memory agent runtime so the end-to-end flow never touches a real AI engine.
 */
class FlowSugFakeRuntime implements AgentRuntimeContract
{
    public function __construct(private readonly string $message)
    {
    }

    public function name(): string
    {
        return 'flowsug-fake-runtime';
    }

    public function capabilities(): AgentRuntimeCapabilities
    {
        return new AgentRuntimeCapabilities();
    }

    public function process(string $message, string $sessionId, mixed $userId, array $options = []): AgentResponse
    {
        return AgentResponse::conversational(
            message: $this->message,
            context: new UnifiedActionContext($sessionId, is_int($userId) ? $userId : 0)
        );
    }
}

/**
 * Unused tool stub kept FlowSug-prefixed for global uniqueness; demonstrates the tool
 * surface the suggestion service consumes without registering real tools.
 */
class FlowSugUnusedTool extends AgentTool
{
    public function getName(): string
    {
        return 'flowsug_unused_tool';
    }

    public function getDescription(): string
    {
        return 'A tool that is intentionally not registered in these tests.';
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
