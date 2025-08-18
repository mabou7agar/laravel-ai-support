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
    protected $mockDatabaseDriver;
    protected $mockRedisDriver;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockMetricsCollector = Mockery::mock(MetricsCollector::class);
        $this->mockDatabaseDriver = Mockery::mock(DatabaseAnalyticsDriver::class);
        $this->mockRedisDriver = Mockery::mock(RedisAnalyticsDriver::class);
        
        $this->analyticsManager = new AnalyticsManager($this->mockMetricsCollector);
        $this->analyticsManager->registerDriver('database', $this->mockDatabaseDriver);
        $this->analyticsManager->registerDriver('redis', $this->mockRedisDriver);
    }

    public function test_can_track_request()
    {
        $data = [
            'engine' => 'openai',
            'model' => 'gpt-4o',
            'tokens' => 150,
            'cost' => 0.003,
            'duration' => 1.5
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('track')
            ->once()
            ->with('request', $data)
            ->andReturn(true);

        $this->mockMetricsCollector
            ->shouldReceive('incrementCounter')
            ->once()
            ->with('requests.total');

        $this->mockMetricsCollector
            ->shouldReceive('recordGauge')
            ->once()
            ->with('requests.tokens', 150);

        $result = $this->analyticsManager->trackRequest($data);
        
        $this->assertTrue($result);
    }

    public function test_can_track_streaming()
    {
        $data = [
            'session_id' => 'session-123',
            'duration' => 5.2,
            'chunks_sent' => 25,
            'bytes_sent' => 1024
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('track')
            ->once()
            ->with('streaming', $data)
            ->andReturn(true);

        $this->mockMetricsCollector
            ->shouldReceive('incrementCounter')
            ->once()
            ->with('streaming.sessions');

        $result = $this->analyticsManager->trackStreaming($data);
        
        $this->assertTrue($result);
    }

    public function test_can_track_action()
    {
        $data = [
            'action_type' => 'button',
            'action_id' => 'btn-123',
            'session_id' => 'session-456',
            'response_time' => 0.8
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('track')
            ->once()
            ->with('action', $data)
            ->andReturn(true);

        $this->mockMetricsCollector
            ->shouldReceive('incrementCounter')
            ->once()
            ->with('actions.total');

        $result = $this->analyticsManager->trackAction($data);
        
        $this->assertTrue($result);
    }

    public function test_can_track_error()
    {
        $data = [
            'error_type' => 'api_error',
            'error_message' => 'Rate limit exceeded',
            'engine' => 'openai',
            'context' => ['request_id' => 'req-123']
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('track')
            ->once()
            ->with('error', $data)
            ->andReturn(true);

        $this->mockMetricsCollector
            ->shouldReceive('incrementCounter')
            ->once()
            ->with('errors.total');

        $result = $this->analyticsManager->trackError($data);
        
        $this->assertTrue($result);
    }

    public function test_can_get_dashboard_data()
    {
        $filters = ['date_from' => '2024-01-01', 'date_to' => '2024-01-31'];
        $expectedData = [
            'total_requests' => 1000,
            'total_cost' => 15.50,
            'average_response_time' => 1.2,
            'top_engines' => ['openai' => 600, 'anthropic' => 400]
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('query')
            ->once()
            ->with('dashboard', $filters)
            ->andReturn($expectedData);

        $result = $this->analyticsManager->getDashboardData($filters);
        
        $this->assertEquals($expectedData, $result);
    }

    public function test_can_get_real_time_metrics()
    {
        $expectedMetrics = [
            'requests_per_minute' => 25,
            'active_sessions' => 12,
            'error_rate' => 0.02,
            'average_response_time' => 1.1
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
        $options = [
            'type' => 'monthly',
            'format' => 'json',
            'include_charts' => true
        ];

        $expectedReport = [
            'period' => '2024-01',
            'summary' => ['total_requests' => 5000, 'total_cost' => 75.25],
            'charts' => ['usage_trend' => [], 'cost_breakdown' => []]
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('query')
            ->once()
            ->with('report', $options)
            ->andReturn($expectedReport);

        $result = $this->analyticsManager->generateReport($options);
        
        $this->assertEquals($expectedReport, $result);
    }

    public function test_can_get_usage_insights()
    {
        $expectedInsights = [
            'peak_usage_hours' => ['14:00', '15:00', '16:00'],
            'most_used_engines' => ['openai', 'anthropic'],
            'cost_trends' => 'increasing',
            'recommendations' => ['Consider rate limiting during peak hours']
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('query')
            ->once()
            ->with('insights', [])
            ->andReturn($expectedInsights);

        $result = $this->analyticsManager->getUsageInsights();
        
        $this->assertEquals($expectedInsights, $result);
    }

    public function test_can_get_cost_analysis()
    {
        $filters = ['engine' => 'openai'];
        $expectedAnalysis = [
            'total_cost' => 25.75,
            'cost_per_request' => 0.0052,
            'cost_breakdown' => ['tokens' => 20.50, 'requests' => 5.25],
            'projections' => ['monthly' => 77.25]
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('query')
            ->once()
            ->with('cost_analysis', $filters)
            ->andReturn($expectedAnalysis);

        $result = $this->analyticsManager->getCostAnalysis($filters);
        
        $this->assertEquals($expectedAnalysis, $result);
    }

    public function test_can_get_performance_metrics()
    {
        $expectedMetrics = [
            'average_response_time' => 1.25,
            'p95_response_time' => 2.1,
            'p99_response_time' => 3.5,
            'success_rate' => 0.98,
            'error_rate' => 0.02
        ];

        $this->mockDatabaseDriver
            ->shouldReceive('query')
            ->once()
            ->with('performance', [])
            ->andReturn($expectedMetrics);

        $result = $this->analyticsManager->getPerformanceMetrics();
        
        $this->assertEquals($expectedMetrics, $result);
    }

    public function test_can_register_driver()
    {
        $customDriver = Mockery::mock(DatabaseAnalyticsDriver::class);
        
        $this->analyticsManager->registerDriver('custom', $customDriver);
        
        $drivers = $this->analyticsManager->getAvailableDrivers();
        $this->assertArrayHasKey('custom', $drivers);
    }

    public function test_can_switch_driver()
    {
        $this->analyticsManager->setDriver('redis');
        
        $currentDriver = $this->analyticsManager->getCurrentDriver();
        $this->assertEquals('redis', $currentDriver);
    }

    public function test_can_get_available_drivers()
    {
        $drivers = $this->analyticsManager->getAvailableDrivers();
        
        $this->assertIsArray($drivers);
        $this->assertArrayHasKey('database', $drivers);
        $this->assertArrayHasKey('redis', $drivers);
    }

    public function test_can_cleanup_old_data()
    {
        $retentionDays = 90;

        $this->mockDatabaseDriver
            ->shouldReceive('cleanup')
            ->once()
            ->with($retentionDays)
            ->andReturn(150); // Number of records cleaned

        $result = $this->analyticsManager->cleanupOldData($retentionDays);
        
        $this->assertEquals(150, $result);
    }

    public function test_can_export_data()
    {
        $filters = ['date_from' => '2024-01-01'];
        $format = 'csv';
        $expectedExport = 'csv,data,here';

        $this->mockDatabaseDriver
            ->shouldReceive('export')
            ->once()
            ->with($filters, $format)
            ->andReturn($expectedExport);

        $result = $this->analyticsManager->exportData($filters, $format);
        
        $this->assertEquals($expectedExport, $result);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
