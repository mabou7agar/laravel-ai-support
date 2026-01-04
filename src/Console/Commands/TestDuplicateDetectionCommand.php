<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\DuplicateDetectionService;

class TestDuplicateDetectionCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ai:test-duplicate-detection
                            {--message= : Message to send (e.g., "Create invoice for John Doe")}
                            {--session= : Session ID for conversation}
                            {--model= : Model class to test (e.g., "App\\Models\\Customer")}
                            {--search= : Search value to test duplicate detection}';

    /**
     * The console command description.
     */
    protected $description = 'Test duplicate detection system for AI chat';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Testing Duplicate Detection System');
        $this->newLine();

        $message = $this->option('message');
        $sessionId = $this->option('session') ?? 'test-duplicate-' . uniqid();
        $modelClass = $this->option('model');
        $searchValue = $this->option('search');

        // Test Mode 1: Direct duplicate detection test
        if ($modelClass && $searchValue) {
            return $this->testDirectDuplicateDetection($modelClass, $searchValue);
        }

        // Test Mode 2: Full chat flow test
        if ($message) {
            return $this->testChatFlow($message, $sessionId);
        }

        // Interactive mode
        return $this->interactiveTest();
    }

    /**
     * Test duplicate detection directly
     */
    protected function testDirectDuplicateDetection(string $modelClass, string $searchValue): int
    {
        $this->info("Testing duplicate detection for: {$modelClass}");
        $this->info("Search value: {$searchValue}");
        $this->newLine();

        try {
            if (!class_exists($modelClass)) {
                $this->error("Model class not found: {$modelClass}");
                return 1;
            }

            $duplicateService = app(DuplicateDetectionService::class);
            
            // Build search data
            $searchData = ['name' => $searchValue];
            if (filter_var($searchValue, FILTER_VALIDATE_EMAIL)) {
                $searchData['email'] = $searchValue;
            }

            $this->info('🔎 Searching for existing records...');
            $startTime = microtime(true);
            
            $results = $duplicateService->searchExistingRecords(
                $modelClass,
                $searchData,
                ['name', 'email']
            );
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("✅ Search completed in {$duration}ms");
            $this->newLine();

            if (empty($results)) {
                $this->warn('No existing records found');
                $this->info('→ System would create new record');
                return 0;
            }

            $this->info("📊 Found " . count($results) . " potential match(es):");
            $this->newLine();

            $headers = ['#', 'ID', 'Name', 'Email', 'Similarity', 'Source', 'Action'];
            $rows = [];

            foreach ($results as $index => $result) {
                $num = $index + 1;
                $data = $result['data'];
                $similarity = round($result['similarity'] * 100);
                
                // Determine action
                if ($result['similarity'] >= 0.9) {
                    $action = '✅ Auto-use';
                } elseif ($result['similarity'] >= 0.7) {
                    $action = '🤔 Ask user';
                } else {
                    $action = '➕ Create new';
                }

                $rows[] = [
                    $num,
                    $result['id'],
                    $data['name'] ?? 'N/A',
                    $data['email'] ?? 'N/A',
                    "{$similarity}%",
                    $result['source'],
                    $action
                ];
            }

            $this->table($headers, $rows);
            $this->newLine();

            // Show what would happen
            $topMatch = $results[0];
            if ($topMatch['similarity'] >= 0.9) {
                $this->info("🎯 Decision: Auto-use existing record (ID: {$topMatch['id']})");
                $this->line("   Reason: High similarity ({$topMatch['similarity']}%)");
            } elseif ($topMatch['similarity'] >= 0.7) {
                $this->info("🤔 Decision: Ask user to choose");
                $this->line("   Reason: Medium similarity ({$topMatch['similarity']}%)");
                $this->newLine();
                $this->line("User would see:");
                $modelName = class_basename($modelClass);
                $formatted = $duplicateService->formatExistingRecordsForUser($results, $modelName);
                $this->line($formatted);
            } else {
                $this->info("➕ Decision: Create new record");
                $this->line("   Reason: Low similarity ({$topMatch['similarity']}%)");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Test full chat flow with duplicate detection
     */
    protected function testChatFlow(string $message, string $sessionId): int
    {
        $this->info("Testing chat flow with duplicate detection");
        $this->info("Session ID: {$sessionId}");
        $this->info("Message: {$message}");
        $this->newLine();

        try {
            $chatService = app(ChatService::class);
            
            $this->info('🚀 Sending message to AI...');
            $startTime = microtime(true);
            
            // Use the processMessage method from ChatService
            $response = $chatService->processMessage(
                message: $message,
                sessionId: $sessionId,
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: true,
                userId: 1
            );
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("✅ Response received in {$duration}ms");
            $this->newLine();

            // Display response
            $this->info('💬 AI Response:');
            $this->line('─────────────────────────────────────────────────');
            $this->line($response->getContent());
            $this->line('─────────────────────────────────────────────────');
            $this->newLine();

            // Check for duplicate detection in metadata
            $metadata = $response->getMetadata();
            if (isset($metadata['pending_duplicate_choices'])) {
                $this->warn('🔍 Duplicate detection triggered!');
                $this->info('Pending duplicate choices found in metadata');
                $this->newLine();
                
                foreach ($metadata['pending_duplicate_choices'] as $field => $choices) {
                    $this->line("Field: {$field}");
                    $this->line("Model: {$choices['model_class']}");
                    $this->line("Search: {$choices['search_value']}");
                    $this->line("Options: " . count($choices['existing_records']));
                }
            }

            // Display actions if any
            if (isset($metadata['actions']) && !empty($metadata['actions'])) {
                $this->info('🎯 Interactive Actions:');
                foreach ($metadata['actions'] as $action) {
                    $this->line("  • {$action->label}");
                }
                $this->newLine();
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->line('Trace: ' . $e->getTraceAsString());
            return 1;
        }
    }

    /**
     * Interactive test mode
     */
    protected function interactiveTest(): int
    {
        $this->info('🎮 Interactive Duplicate Detection Test');
        $this->newLine();

        $mode = $this->choice(
            'Select test mode',
            [
                'direct' => 'Direct duplicate detection (test service only)',
                'chat' => 'Full chat flow (test complete system)',
            ],
            'chat'
        );

        $this->newLine();

        if ($mode === 'direct') {
            // Direct test
            $modelClass = $this->ask('Enter model class (e.g., App\\Models\\Customer)');
            $searchValue = $this->ask('Enter search value (name or email)');
            
            return $this->testDirectDuplicateDetection($modelClass, $searchValue);
        } else {
            // Chat flow test
            $this->info('💡 Example messages:');
            $this->line('  • "Create invoice for Mohamed Abou Hagar - m.abou7agar@gmail.com"');
            $this->line('  • "Add customer John Doe with email john@example.com"');
            $this->line('  • "Create order for existing customer Sarah"');
            $this->newLine();
            
            $message = $this->ask('Enter your message');
            $sessionId = 'test-' . uniqid();
            
            return $this->testChatFlow($message, $sessionId);
        }
    }
}
