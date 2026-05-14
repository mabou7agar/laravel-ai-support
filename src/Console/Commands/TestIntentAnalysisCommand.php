<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\ChatService;

class TestIntentAnalysisCommand extends Command
{
    protected $signature = 'ai:test-intent
                            {--session= : Session ID for conversation}
                            {--disable : Disable intent analysis for comparison}';

    protected $description = 'Test AI Intent Analysis feature';

    public function handle(ChatService $chatService)
    {
        $this->info('🧠 Testing AI Intent Analysis Feature');
        $this->newLine();

        $sessionId = $this->option('session') ?? 'intent-test-' . uniqid();
        $disableIntent = $this->option('disable');

        // Temporarily override config if needed
        if ($disableIntent) {
            config(['ai-engine.actions.intent_analysis' => false]);
            $this->warn('⚠️  Intent Analysis: DISABLED (for comparison)');
        } else {
            $this->info('✅ Intent Analysis: ENABLED');
        }

        $this->info("Session ID: {$sessionId}");
        $this->newLine();

        // Test Scenario 1: Create Record Request
        $this->info('📝 Test 1: Initial Record Creation Request');
        $this->line('User: "create a record called Sample Item for 2499"');
        $this->newLine();

        try {
            $response1 = $chatService->processMessage(
                message: 'create a record called Sample Item for 2499',
                sessionId: $sessionId,
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: true,
                useRag: false
            );

            $this->info('💬 AI Response:');
            $this->line('─────────────────────────────────────────────────');
            $this->line(substr($response1->getContent(), 0, 200) . '...');
            $this->line('─────────────────────────────────────────────────');
            
            $metadata = $response1->getMetadata();
            if (isset($metadata['intent_enhanced'])) {
                $this->info('🎯 Intent Analysis: ' . ($metadata['intent_enhanced'] ? 'Used' : 'Not Used'));
            }
            
            $this->newLine();

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        // Test Scenario 2: Confirmation
        $this->info('📝 Test 2: User Confirms with "yes"');
        $this->line('User: "yes"');
        $this->newLine();

        try {
            $startTime = microtime(true);
            
            $response2 = $chatService->processMessage(
                message: 'yes',
                sessionId: $sessionId,
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: true,
                useRag: false
            );

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info('💬 AI Response:');
            $this->line('─────────────────────────────────────────────────');
            $this->line($response2->getContent());
            $this->line('─────────────────────────────────────────────────');
            
            $metadata = $response2->getMetadata();
            
            $this->newLine();
            $this->info('📊 Analysis Results:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Response Time', $duration . 'ms'],
                    ['Action Executed', isset($metadata['action_executed']) ? '✅ Yes' : '❌ No'],
                    ['Intent Enhanced', isset($metadata['intent_enhanced']) ? '✅ Yes' : '❌ No'],
                    ['Early Confirmation', isset($metadata['early_confirmation']) ? '✅ Yes' : '❌ No'],
                ]
            );

            if (isset($metadata['intent_analysis'])) {
                $this->newLine();
                $this->info('🔍 Intent Analysis Details:');
                $analysis = $metadata['intent_analysis'];
                $this->table(
                    ['Property', 'Value'],
                    [
                        ['Intent', $analysis['intent'] ?? 'N/A'],
                        ['Confidence', ($analysis['confidence'] ?? 0) * 100 . '%'],
                        ['Context', $analysis['context_enhancement'] ?? 'N/A'],
                    ]
                );
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        // Test Scenario 3: Natural Language Confirmation
        $this->info('📝 Test 3: Natural Language Confirmation');
        $this->line('User: "I don\'t mind, create it"');
        $this->newLine();

        try {
            $startTime = microtime(true);
            
            $response3 = $chatService->processMessage(
                message: "I don't mind, create it",
                sessionId: $sessionId . '-nl',
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: true,
                useRag: false
            );

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info('💬 AI Response:');
            $this->line('─────────────────────────────────────────────────');
            $this->line(substr($response3->getContent(), 0, 200) . '...');
            $this->line('─────────────────────────────────────────────────');
            
            $metadata = $response3->getMetadata();
            
            $this->newLine();
            $this->info('📊 Natural Language Analysis:');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Response Time', $duration . 'ms'],
                    ['Intent Detected', isset($metadata['intent_analysis']) ? $metadata['intent_analysis']['intent'] : 'N/A'],
                    ['Confidence', isset($metadata['intent_analysis']) ? ($metadata['intent_analysis']['confidence'] * 100) . '%' : 'N/A'],
                ]
            );

            $this->newLine();

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            return 1;
        }

        // Summary
        $this->info('✅ All Intent Analysis Tests Completed!');
        $this->newLine();
        
        if (!$disableIntent) {
            $this->info('💡 Tip: Run with --disable to compare performance without intent analysis');
        } else {
            $this->info('💡 Tip: Run without --disable to see intent analysis in action');
        }

        return 0;
    }
}
