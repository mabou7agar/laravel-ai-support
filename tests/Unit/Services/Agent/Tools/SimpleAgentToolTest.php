<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\Tools;

use LaravelAIEngine\DTOs\ActionResult;
use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\Tools\SimpleAgentTool;
use LaravelAIEngine\Tests\UnitTestCase;

class SimpleAgentToolTest extends UnitTestCase
{
    public function test_simple_tool_uses_properties_and_normalizes_array_result(): void
    {
        $tool = new GreetingSimpleTool();

        $this->assertSame('customers.greet', $tool->getName());
        $this->assertSame('Greet a customer.', $tool->getDescription());
        $this->assertTrue($tool->requiresConfirmation());
        $this->assertSame('Confirm greeting?', $tool->getConfirmationMessage());

        $result = $tool->execute(['name' => 'Mohamed'], new UnifiedActionContext('simple-tool-test', 11));

        $this->assertTrue($result->success);
        $this->assertSame('Greeting ready.', $result->message);
        $this->assertSame('Mohamed', $result->data['name']);
        $this->assertSame(11, $result->data['user_id']);
        $this->assertTrue($result->metadata['simple_tool']);
    }

    public function test_simple_tool_validates_required_parameters_before_handle(): void
    {
        $result = (new GreetingSimpleTool())->execute([], new UnifiedActionContext('simple-tool-validation'));

        $this->assertFalse($result->success);
        $this->assertSame('Tool parameters are invalid.', $result->error);
        $this->assertSame(['Missing required parameter: name'], $result->metadata['validation_errors']);
    }

    public function test_simple_tool_can_return_action_result_directly(): void
    {
        $result = (new DirectActionResultSimpleTool())->execute([], new UnifiedActionContext('simple-tool-result'));

        $this->assertTrue($result->success);
        $this->assertSame('Direct result.', $result->message);
    }
}

class GreetingSimpleTool extends SimpleAgentTool
{
    public string $name = 'customers.greet';

    public string $description = 'Greet a customer.';

    public array $parameters = [
        'name' => ['type' => 'string', 'required' => true],
    ];

    public bool $requiresConfirmation = true;

    public ?string $confirmationMessage = 'Confirm greeting?';

    protected function handle(array $parameters, UnifiedActionContext $context): array
    {
        return [
            'message' => 'Greeting ready.',
            'data' => [
                'name' => $parameters['name'],
                'user_id' => $context->userId,
            ],
            'metadata' => ['simple_tool' => true],
        ];
    }
}

class DirectActionResultSimpleTool extends SimpleAgentTool
{
    protected function handle(array $parameters, UnifiedActionContext $context): ActionResult
    {
        return ActionResult::success('Direct result.');
    }
}
