<?php

namespace LaravelAIEngine\Tests\Unit\Services\Analytics;

use LaravelAIEngine\Services\Analytics\AnalyticsManager;
use LaravelAIEngine\Services\Analytics\Metrics\MetricsCollector;
use LaravelAIEngine\Services\Analytics\Drivers\DatabaseAnalyticsDriver;
use LaravelAIEngine\Services\Analytics\Drivers\RedisAnalyticsDriver;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class AnalyticsManagerTest extends TestCase
{
    protected AnalyticsManager $analyticsManager;
    protected $mockMetricsCollector;
    protected $mockDriver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockMetricsCollector = Mockery::mock(MetricsCollector::class)->shouldIgnoreMissing();
        $this->mockDriver = Mockery::mock(\LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface::class)->shouldIgnoreMissing();

        $this->app->instance(MetricsCollector::class, $this->mockMetricsCollector);
        $this->app->instance(DatabaseAnalyticsDriver::class, $this->mockDriver);
        $this->app->instance(RedisAnalyticsDriver::class, $this->mockDriver);

        $this->analyticsManager = new AnalyticsManager($this->mockMetricsCollector);
    }

    public function test_can_track_request()
    {
        $data = [
            'engine' => 'openai',
            'model' => 'gpt-4o',
            'total_tokens' => 150,
            'cost' => 0.003,
        ];

        $this->mockDriver
            ->shouldReceive('trackRequest')
            ->once();

        $this->mockMetricsCollector
            ->shouldReceive('recordRequest')
            ->once();

        $this->analyticsManager->trackRequest($data);
        $this->assertTrue(true);
    }

    public function test_can_track_streaming()
    {
        $data = [
            'session_id' => 'session-123',
            'duration' => 5.2,
            'chunks_sent' => 25,
        ];

        $this->mockDriver
            ->shouldReceive('trackStreaming')
            ->once();

        $this->mockMetricsCollector
            ->shouldReceive('recordStreaming')
            ->once();

        $this->analyticsManager->trackStreaming($data);
        $this->assertTrue(true);
    }

    public function test_can_track_action()
    {
        $data = [
            'action_type' => 'button',
            'action_id' => 'btn-123',
            'session_id' => 'session-456',
        ];

        $this->mockDriver
            ->shouldReceive('trackAction')
            ->once();

        $this->mockMetricsCollector
            ->shouldReceive('recordAction')
            ->once();

        $this->analyticsManager->trackAction($data);
        $this->assertTrue(true);
    }

    public function test_can_get_dashboard_data()
    {
        $result = $this->analyticsManager->getDashboardData();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('generated_at', $result);
    }

    public function test_can_get_real_time_metrics()
    {
        $expectedMetrics = [
            'requests_per_minute' => 25,
            'active_sessions' => 12,
        ];

        $this->mockMetricsCollector
            ->shouldReceive('getMetrics')
            ->once()
            ->andReturn($expectedMetrics);

        $result = $this->analyticsManager->getRealTimeMetrics();
        
        $this->assertEquals($expectedMetrics, $result);
    }

    public function test_can_generate_report()
    {
        $result = $this->analyticsManager->generateReport(['time_range' => '7d']);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('generated_at', $result);
    }

    public function test_can_get_usage_analytics()
    {
        $this->mockDriver
            ->shouldReceive('getUsageAnalytics')
            ->once()
            ->andReturn(['total_requests' => 100]);

        $result = $this->analyticsManager->getUsageAnalytics();
        
        $this->assertIsArray($result);
    }

    public function test_can_get_cost_analytics()
    {
        $this->mockDriver
            ->shouldReceive('getCostAnalytics')
            ->once()
            ->andReturn(['total_cost' => 25.75]);

        $result = $this->analyticsManager->getCostAnalytics();
        
        $this->assertIsArray($result);
    }

    public function test_can_get_performance_metrics()
    {
        $this->mockDriver
            ->shouldReceive('getPerformanceMetrics')
            ->once()
            ->andReturn(['avg_response_time' => 1.25]);

        $result = $this->analyticsManager->getPerformanceMetrics();
        
        $this->assertIsArray($result);
    }

    public function test_can_extend_with_custom_driver()
    {
        $customDriver = Mockery::mock(\LaravelAIEngine\Services\Analytics\Contracts\AnalyticsDriverInterface::class);
        
        $this->analyticsManager->extend('custom', $customDriver);
        
        $driver = $this->analyticsManager->driver('custom');
        $this->assertSame($customDriver, $driver);
    }

    public function test_can_get_system_health()
    {
        $result = $this->analyticsManager->getSystemHealth();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('checks', $result);
    }

    public function test_driver_returns_default_driver()
    {
        $driver = $this->analyticsManager->driver();
        
        $this->assertNotNull($driver);
    }

    public function test_driver_throws_for_unknown_driver()
    {
        $this->expectException(\InvalidArgumentException::class);
        
        $this->analyticsManager->driver('nonexistent');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
