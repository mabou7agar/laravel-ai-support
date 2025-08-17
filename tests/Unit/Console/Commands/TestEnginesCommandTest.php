<?php

namespace MagicAI\LaravelAIEngine\Tests\Unit\Console\Commands;

use MagicAI\LaravelAIEngine\Tests\TestCase;
use MagicAI\LaravelAIEngine\Console\Commands\TestEnginesCommand;
use MagicAI\LaravelAIEngine\Enums\EngineEnum;
use MagicAI\LaravelAIEngine\Enums\EntityEnum;
use Illuminate\Support\Facades\Artisan;

class TestEnginesCommandTest extends TestCase
{
    public function test_command_exists()
    {
        $this->assertTrue(class_exists(TestEnginesCommand::class));
    }

    public function test_command_signature()
    {
        $command = new TestEnginesCommand();
        $this->assertEquals('ai-engine:test-engines', $command->getName());
    }

    public function test_command_runs_successfully()
    {
        // Mock the engine drivers to avoid actual API calls
        $this->mockAllEngineDrivers();

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'openai',
            '--quick' => true
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Testing AI Engines', $output);
        $this->assertStringContainsString('openai', $output);
    }

    public function test_command_with_specific_engine()
    {
        $this->mockAllEngineDrivers();

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'openai'
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('openai', $output);
        $this->assertStringNotContainsString('anthropic', $output);
    }

    public function test_command_with_specific_model()
    {
        $this->mockAllEngineDrivers();

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--model' => 'gpt-4o'
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('gpt-4o', $output);
    }

    public function test_command_with_verbose_output()
    {
        $this->mockAllEngineDrivers();

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'openai',
            '--verbose' => true,
            '--quick' => true
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Content:', $output);
        $this->assertStringContainsString('Credits:', $output);
    }

    public function test_command_with_export_option()
    {
        $this->mockAllEngineDrivers();

        $exportPath = storage_path('app/test-results.json');
        
        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'openai',
            '--export' => $exportPath,
            '--quick' => true
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('exported to', $output);
    }

    public function test_command_handles_invalid_engine()
    {
        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'invalid-engine'
        ]);

        $this->assertNotEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Invalid engine', $output);
    }

    public function test_command_handles_missing_api_keys()
    {
        // Clear API keys
        config(['ai-engine.engines.openai.api_key' => '']);

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--engine' => 'openai',
            '--quick' => true
        ]);

        $output = Artisan::output();
        $this->assertStringContainsString('API key not configured', $output);
    }

    public function test_command_shows_summary()
    {
        $this->mockAllEngineDrivers();

        $exitCode = Artisan::call('ai-engine:test-engines', [
            '--quick' => true
        ]);

        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Test Summary', $output);
        $this->assertStringContainsString('Models Tested', $output);
        $this->assertStringContainsString('Passed', $output);
        $this->assertStringContainsString('Failed', $output);
    }

    private function mockAllEngineDrivers()
    {
        // Mock successful responses for all engines
        $this->mockHttpClient([
            // OpenAI response
            [
                'choices' => [
                    ['message' => ['content' => 'Test response']]
                ],
                'usage' => ['total_tokens' => 10]
            ],
            // Anthropic response
            [
                'content' => [
                    ['text' => 'Test response']
                ],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5]
            ],
            // Generic success response for other engines
            [
                'success' => true,
                'data' => 'Test response'
            ]
        ]);
    }
}
