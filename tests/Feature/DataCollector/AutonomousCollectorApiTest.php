<?php

namespace LaravelAIEngine\Tests\Feature\DataCollector;

use LaravelAIEngine\DTOs\AutonomousCollectorConfig;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorResponse;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorService;
use LaravelAIEngine\Tests\UnitTestCase;
use Mockery;

class AutonomousCollectorApiTest extends UnitTestCase
{
    protected AutonomousCollectorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        AutonomousCollectorRegistry::clear();

        $this->service = Mockery::mock(AutonomousCollectorService::class);
        $this->service->shouldReceive('getRegisteredConfig')->byDefault()->andReturnNull();
        $this->app->instance(AutonomousCollectorService::class, $this->service);
    }

    protected function tearDown(): void
    {
        AutonomousCollectorRegistry::clear();
        parent::tearDown();
    }

    public function test_start_endpoint_uses_registered_autonomous_config(): void
    {
        AutonomousCollectorRegistry::register('invoice_auto', [
            'config' => fn () => new AutonomousCollectorConfig(
                name: 'invoice_auto',
                goal: 'Create invoice',
                description: 'Collect invoice details',
                outputSchema: ['customer_id' => 'integer|required'],
            ),
            'description' => 'Invoice collector',
        ]);

        $this->service->shouldReceive('start')
            ->once()
            ->andReturn(new AutonomousCollectorResponse(
                success: true,
                message: 'Started',
                status: 'collecting',
                collectedData: [],
                turnCount: 0
            ));

        $response = $this->postJson('/api/v1/autonomous-collector/start', [
            'config_name' => 'invoice_auto',
            'session_id' => 'ac-test-1',
            'initial_message' => 'Create invoice',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'collecting')
            ->assertJsonPath('session_id', 'ac-test-1');
    }

    public function test_start_endpoint_returns_not_found_when_config_is_missing(): void
    {
        $this->service->shouldNotReceive('start');

        $this->postJson('/api/v1/autonomous-collector/start', [
            'config_name' => 'missing_collector',
            'session_id' => 'ac-test-2',
        ])->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_message_and_confirm_endpoints_return_collector_response_payload(): void
    {
        $this->service->shouldReceive('process')
            ->once()
            ->with('ac-test-3', 'Next step')
            ->andReturn(new AutonomousCollectorResponse(
                success: true,
                message: 'Need confirmation',
                status: 'confirming',
                collectedData: ['customer_id' => 88],
                requiresConfirmation: true,
                turnCount: 2
            ));

        $this->service->shouldReceive('confirm')
            ->once()
            ->with('ac-test-3')
            ->andReturn(new AutonomousCollectorResponse(
                success: true,
                message: 'Completed',
                status: 'completed',
                collectedData: ['customer_id' => 88],
                isComplete: true,
                result: ['id' => 1001],
                turnCount: 3
            ));

        $this->postJson('/api/v1/autonomous-collector/message', [
            'session_id' => 'ac-test-3',
            'message' => 'Next step',
        ])->assertOk()
            ->assertJsonPath('status', 'confirming')
            ->assertJsonPath('requires_confirmation', true);

        $this->postJson('/api/v1/autonomous-collector/confirm', [
            'session_id' => 'ac-test-3',
        ])->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('is_complete', true);
    }

    public function test_status_and_data_endpoints_handle_missing_or_existing_sessions(): void
    {
        $this->service->shouldReceive('hasSession')
            ->once()
            ->with('ac-test-4')
            ->andReturn(false);

        $this->getJson('/api/v1/autonomous-collector/status/ac-test-4')
            ->assertStatus(404)
            ->assertJsonPath('success', false);

        $this->service->shouldReceive('hasSession')
            ->once()
            ->with('ac-test-5')
            ->andReturn(true);
        $this->service->shouldReceive('getStatus')
            ->once()
            ->with('ac-test-5')
            ->andReturn('collecting');
        $this->service->shouldReceive('getData')
            ->once()
            ->with('ac-test-5')
            ->andReturn(['customer_id' => 5]);

        $this->getJson('/api/v1/autonomous-collector/data/ac-test-5')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'collecting')
            ->assertJsonPath('data.customer_id', 5);
    }
}
