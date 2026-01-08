<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\DataCollector\DataCollectorService;
use LaravelAIEngine\DTOs\DataCollectorConfig;

class TestDataCollectorHallucinationCommand extends Command
{
    protected $signature = 'ai:test-hallucination
                            {--engine=openai : AI engine to use}
                            {--model=gpt-4o : AI model to use}';

    protected $description = 'Comprehensive hallucination test for DataCollector';

    public function handle(DataCollectorService $dataCollector)
    {
        $this->info('🧪 DataCollector Hallucination Test Suite');
        $this->newLine();

        $engine = $this->option('engine');
        $model = $this->option('model');

        $passedTests = 0;
        $failedTests = 0;
        $totalTests = 0;

        // Test 1: Initial data should not be re-asked
        $this->info('Test 1: Initial Data - Should skip pre-filled fields');
        $result1 = $this->testInitialData($dataCollector, $engine, $model);
        $totalTests++;
        if ($result1) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Test 2: Multiple values in one message
        $this->info('Test 2: Multiple Values - Should only extract current field');
        $result2 = $this->testMultipleValues($dataCollector, $engine, $model);
        $totalTests++;
        if ($result2) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Test 3: User mentions already collected field
        $this->info('Test 3: Already Collected - Should not overwrite existing data');
        $result3 = $this->testAlreadyCollected($dataCollector, $engine, $model);
        $totalTests++;
        if ($result3) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Test 4: Ambiguous input
        $this->info('Test 4: Ambiguous Input - Should ask for clarification');
        $result4 = $this->testAmbiguousInput($dataCollector, $engine, $model);
        $totalTests++;
        if ($result4) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Test 5: Wrong field value type
        $this->info('Test 5: Type Mismatch - Should extract correct type');
        $result5 = $this->testTypeMismatch($dataCollector, $engine, $model);
        $totalTests++;
        if ($result5) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Test 6: Question instead of value
        $this->info('Test 6: User Question - Should not extract value');
        $result6 = $this->testUserQuestion($dataCollector, $engine, $model);
        $totalTests++;
        if ($result6) {
            $passedTests++;
            $this->info('✅ PASSED');
        } else {
            $failedTests++;
            $this->error('❌ FAILED');
        }
        $this->newLine();

        // Summary
        $this->info('═══════════════════════════════════════════════');
        $this->info("Test Results: {$passedTests}/{$totalTests} passed");
        if ($failedTests > 0) {
            $this->error("{$failedTests} tests failed");
        } else {
            $this->info('🎉 All tests passed! No hallucination detected.');
        }
        $this->info('═══════════════════════════════════════════════');

        return $failedTests === 0 ? 0 : 1;
    }

    protected function testInitialData($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-initial-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_initial',
            title: 'Test Initial Data',
            fields: [
                'name' => 'Course name | required | min:3',
                'description' => 'Description | required | min:20',
            ],
            initialData: ['name' => 'Pre-filled Course'],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // Check: currentField should be 'description', not 'name'
        if ($state->currentField !== 'description') {
            $this->error("  Expected currentField='description', got '{$state->currentField}'");
            return false;
        }

        // Check: name should already be in collected data
        if (!isset($state->getData()['name']) || $state->getData()['name'] !== 'Pre-filled Course') {
            $this->error("  Initial data not properly set");
            return false;
        }

        $this->line("  ✓ Current field correctly set to first uncollected field");
        $this->line("  ✓ Initial data properly stored");
        
        return true;
    }

    protected function testMultipleValues($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-multiple-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_multiple',
            title: 'Test Multiple Values',
            fields: [
                'name' => 'Course name | required',
                'duration' => 'Duration in hours | required | numeric',
                'level' => ['type' => 'select', 'options' => ['beginner', 'intermediate', 'advanced']],
            ],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // User provides multiple values in one message
        $response = $dataCollector->processMessage(
            $sessionId,
            'Laravel Basics for 10 hours at beginner level',
            $engine,
            $model
        );

        // Check: Only 'name' should be collected (current field)
        $data = $response->state->getData();
        
        if (!isset($data['name'])) {
            $this->error("  Name field not collected");
            return false;
        }

        // Duration and level should NOT be collected yet
        if (isset($data['duration']) && !empty($data['duration'])) {
            $this->error("  Hallucination: Duration collected when only name was expected");
            return false;
        }

        if (isset($data['level']) && !empty($data['level'])) {
            $this->error("  Hallucination: Level collected when only name was expected");
            return false;
        }

        $this->line("  ✓ Only current field extracted");
        $this->line("  ✓ Other fields ignored");
        
        return true;
    }

    protected function testAlreadyCollected($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-already-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_already',
            title: 'Test Already Collected',
            fields: [
                'name' => 'Course name | required',
                'description' => 'Description | required',
            ],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // Collect name
        $response1 = $dataCollector->processMessage($sessionId, 'Laravel Fundamentals', $engine, $model);
        $originalName = $response1->state->getData()['name'] ?? null;

        // Now collecting description, but user mentions name again
        $response2 = $dataCollector->processMessage(
            $sessionId,
            'Actually the name should be Advanced Laravel',
            $engine,
            $model
        );

        $data = $response2->state->getData();
        
        // Check: name should NOT be changed
        if ($data['name'] !== $originalName) {
            $this->error("  Hallucination: Already collected field was overwritten");
            $this->error("  Original: {$originalName}");
            $this->error("  Changed to: {$data['name']}");
            return false;
        }

        $this->line("  ✓ Already collected field protected");
        
        return true;
    }

    protected function testAmbiguousInput($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-ambiguous-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_ambiguous',
            title: 'Test Ambiguous Input',
            fields: [
                'description' => 'Course description | required | min:20',
            ],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // User provides ambiguous input
        $response = $dataCollector->processMessage($sessionId, 'maybe', $engine, $model);

        $data = $response->state->getData();
        
        // Check: description should NOT be set to 'maybe'
        if (isset($data['description']) && $data['description'] === 'maybe') {
            $this->error("  Hallucination: Ambiguous input accepted as value");
            return false;
        }

        $this->line("  ✓ Ambiguous input rejected");
        
        return true;
    }

    protected function testTypeMismatch($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-type-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_type',
            title: 'Test Type Mismatch',
            fields: [
                'duration' => 'Duration in hours | required | numeric',
            ],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // User provides numeric value with text
        $response = $dataCollector->processMessage($sessionId, '10 hours', $engine, $model);

        $data = $response->state->getData();
        
        // Check: duration should be extracted as number only
        if (!isset($data['duration'])) {
            $this->error("  Duration not extracted");
            return false;
        }

        $duration = $data['duration'];
        if (!is_numeric($duration)) {
            $this->error("  Type mismatch: Expected numeric, got '{$duration}'");
            return false;
        }

        if ($duration != 10) {
            $this->error("  Wrong value extracted: Expected 10, got {$duration}");
            return false;
        }

        $this->line("  ✓ Numeric value correctly extracted");
        
        return true;
    }

    protected function testUserQuestion($dataCollector, $engine, $model): bool
    {
        $sessionId = 'test-question-' . uniqid();
        
        $config = new DataCollectorConfig(
            name: 'test_question',
            title: 'Test User Question',
            fields: [
                'description' => 'Course description | required',
            ],
        );

        $state = $dataCollector->startSession($sessionId, $config);
        
        // User asks a question instead of providing value
        $response = $dataCollector->processMessage($sessionId, 'What should I write here?', $engine, $model);

        $data = $response->state->getData();
        
        // Check: description should NOT be set to the question
        if (isset($data['description']) && str_contains($data['description'], 'What should I write')) {
            $this->error("  Hallucination: Question accepted as value");
            return false;
        }

        $this->line("  ✓ Question not extracted as value");
        
        return true;
    }
}
