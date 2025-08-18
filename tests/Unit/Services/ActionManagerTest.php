<?php

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\ActionHandlers\ButtonActionHandler;
use LaravelAIEngine\Services\ActionHandlers\QuickReplyActionHandler;
use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\DTOs\ActionResponse;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Exceptions\ActionHandlerNotFoundException;
use LaravelAIEngine\Exceptions\ActionValidationException;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class ActionManagerTest extends TestCase
{
    protected ActionManager $actionManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->actionManager = new ActionManager();
        $this->actionManager->registerHandler(new ButtonActionHandler());
        $this->actionManager->registerHandler(new QuickReplyActionHandler());
    }

    public function test_register_handler()
    {
        $initialCount = $this->actionManager->getHandlers()->count();
        
        $mockHandler = Mockery::mock(\LaravelAIEngine\Contracts\ActionHandlerInterface::class);
        $mockHandler->shouldReceive('priority')->andReturn(100);
        
        $this->actionManager->registerHandler($mockHandler);
        
        $this->assertEquals($initialCount + 1, $this->actionManager->getHandlers()->count());
    }

    public function test_execute_button_action()
    {
        $action = InteractiveAction::button('test', 'Test Button', [
            'action' => [
                'type' => 'callback',
                'callback' => 'testCallback'
            ]
        ]);

        $response = $this->actionManager->executeAction($action, ['test' => 'data']);

        $this->assertInstanceOf(ActionResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertEquals($action->id, $response->actionId);
    }

    public function test_execute_quick_reply_action()
    {
        $action = InteractiveAction::quickReply('reply', 'Quick Reply', 'Hello World');

        $response = $this->actionManager->executeAction($action, []);

        $this->assertInstanceOf(ActionResponse::class, $response);
        $this->assertTrue($response->success);
        $this->assertEquals($action->id, $response->actionId);
        $this->assertEquals('Hello World', $response->data['message']);
    }

    public function test_execute_action_with_no_handler()
    {
        $action = InteractiveAction::fromArray([
            'id' => 'test',
            'type' => 'custom',
            'label' => 'Test',
            'data' => []
        ]);

        $response = $this->actionManager->executeAction($action, []);

        $this->assertInstanceOf(ActionResponse::class, $response);
        $this->assertFalse($response->success);
        $this->assertStringContainsString('No handler found', $response->message);
    }

    public function test_validate_action()
    {
        $action = InteractiveAction::button('test', 'Test Button', [
            'action' => [
                'type' => 'callback',
                'callback' => 'testCallback'
            ]
        ]);

        $errors = $this->actionManager->validateAction($action, []);

        $this->assertEmpty($errors);
    }

    public function test_validate_invalid_action()
    {
        $action = InteractiveAction::button('test', 'Test Button', []);

        $errors = $this->actionManager->validateAction($action, []);

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('action', $errors);
    }

    public function test_get_supported_action_types()
    {
        $supportedTypes = $this->actionManager->getSupportedActionTypes();

        $this->assertIsArray($supportedTypes);
        $this->assertContains('button', $supportedTypes);
        $this->assertContains('quick_reply', $supportedTypes);
    }

    public function test_create_action_from_array()
    {
        $data = [
            'id' => 'test',
            'type' => 'button',
            'label' => 'Test Button',
            'data' => ['action' => ['type' => 'callback']]
        ];

        $action = $this->actionManager->createAction($data);

        $this->assertInstanceOf(InteractiveAction::class, $action);
        $this->assertEquals('test', $action->id);
        $this->assertEquals(ActionTypeEnum::BUTTON, $action->type);
        $this->assertEquals('Test Button', $action->label);
    }

    public function test_create_multiple_actions()
    {
        $actionsData = [
            [
                'id' => 'test1',
                'type' => 'button',
                'label' => 'Test Button 1',
                'data' => []
            ],
            [
                'id' => 'test2',
                'type' => 'quick_reply',
                'label' => 'Test Reply',
                'data' => ['message' => 'Hello']
            ]
        ];

        $actions = $this->actionManager->createActions($actionsData);

        $this->assertIsArray($actions);
        $this->assertCount(2, $actions);
        $this->assertInstanceOf(InteractiveAction::class, $actions[0]);
        $this->assertInstanceOf(InteractiveAction::class, $actions[1]);
    }

    public function test_batch_execute_actions()
    {
        $actions = [
            InteractiveAction::quickReply('reply1', 'Reply 1', 'Hello'),
            InteractiveAction::quickReply('reply2', 'Reply 2', 'World')
        ];

        $responses = $this->actionManager->executeActions($actions, []);

        $this->assertIsArray($responses);
        $this->assertCount(2, $responses);
        $this->assertInstanceOf(ActionResponse::class, $responses[0]);
        $this->assertInstanceOf(ActionResponse::class, $responses[1]);
        $this->assertTrue($responses[0]->success);
        $this->assertTrue($responses[1]->success);
    }

    public function test_get_action_stats()
    {
        $stats = $this->actionManager->getActionStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_handlers', $stats);
        $this->assertArrayHasKey('supported_types', $stats);
        $this->assertArrayHasKey('handlers_by_priority', $stats);
        $this->assertGreaterThan(0, $stats['total_handlers']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
