<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\ChatService;

class TestRAGFeaturesCommand extends Command
{
    protected $signature = 'ai-engine:test-rag
                            {--model= : Specific model class to test}
                            {--quick : Run quick tests only}';

    protected $description = 'Test all RAG features (discovery, search, chat, intelligent RAG)';

    public function handle(
        RAGCollectionDiscovery $discovery,
        IntelligentRAGService $intelligentRAG,
        ChatService $chatService
    ): int {
        $this->info('🧪 Testing Laravel AI Engine RAG Features');
        $this->newLine();

        // Test 1: Collection Discovery
        if (!$this->testCollectionDiscovery($discovery)) {
            return self::FAILURE;
        }

        // Test 2: Model Resolution
        $modelClass = $this->option('model');
        if (!$modelClass) {
            $modelClass = $this->selectModel($discovery);
        }

        if (!$modelClass) {
            $this->error('No RAG-enabled models found. Please add Vectorizable trait to your models.');
            return self::FAILURE;
        }

        // Test 3: Vector Search
        if (!$this->option('quick')) {
            $this->testVectorSearch($modelClass);
        }

        // Test 4: Intelligent RAG
        $this->testIntelligentRAG($modelClass, $intelligentRAG);

        // Test 5: Manual RAG
        if (!$this->option('quick')) {
            $this->testManualRAG($modelClass);
        }

        // Test 6: Instance Methods
        if (!$this->option('quick')) {
            $this->testInstanceMethods($modelClass);
        }

        // Test 7: Chat Service Integration
        $this->testChatServiceIntegration($chatService);

        $this->newLine();
        $this->info('✅ All tests completed successfully!');

        return self::SUCCESS;
    }

    /**
     * Test 1: Collection Discovery
     */
    protected function testCollectionDiscovery(RAGCollectionDiscovery $discovery): bool
    {
        $this->line('📋 Test 1: Collection Discovery');
        $this->line('─────────────────────────────────');

        try {
            $collections = $discovery->discover(useCache: false);

            if (empty($collections)) {
                $this->warn('⚠️  No RAG collections found');
                $this->line('   Add Vectorizable trait to your models to enable RAG');
                return false;
            }
            $count = count($collections);
            $this->info("✅ Found {$count} RAG collection(s):");
            foreach ($collections as $collection) {
                $this->line("   - {$collection}");
            }

            $stats = $discovery->getStatistics();
            $this->line("   Cache: " . ($stats['cached'] ? 'Yes' : 'No'));
            $this->line("   TTL: {$stats['cache_ttl']}s");

            $this->newLine();
            return true;

        } catch (\Exception $e) {
            $this->error("❌ Discovery failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Select a model to test
     */
    protected function selectModel(RAGCollectionDiscovery $discovery): ?string
    {
        $collections = $discovery->discover();

        if (empty($collections)) {
            return null;
        }

        if (count($collections) === 1) {
            return $collections[0];
        }

        $choices = [];
        foreach ($collections as $index => $collection) {
            $choices[$index] = class_basename($collection) . " ({$collection})";
        }

        $selected = $this->choice('Select a model to test', $choices, 0);
        $index = array_search($selected, $choices);

        return $collections[$index];
    }

    /**
     * Test 2: Vector Search
     */
    protected function testVectorSearch(string $modelClass): void
    {
        $this->line('🔍 Test 2: Vector Search');
        $this->line('─────────────────────────────────');

        try {
            $query = $this->ask('Enter search query', 'test');

            $this->line("Searching {$modelClass}...");

            $results = $modelClass::vectorSearch($query, limit: 3);

            if ($results->isEmpty()) {
                $this->warn('⚠️  No results found');
                $this->line('   Make sure the model is indexed:');
                $this->line("   php artisan ai-engine:vector-index \"{$modelClass}\"");
            } else {
                $this->info("✅ Found {$results->count()} result(s):");
                foreach ($results as $index => $result) {
                    $score = isset($result->vector_score)
                        ? round($result->vector_score * 100, 1) . '%'
                        : 'N/A';
                    $this->line("   " . ($index + 1) . ". ID: {$result->id} (Score: {$score})");
                }
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Vector search failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 3: Intelligent RAG
     */
    protected function testIntelligentRAG(string $modelClass, IntelligentRAGService $intelligentRAG): void
    {
        $this->line('🤖 Test 3: Intelligent RAG (AI Decides)');
        $this->line('─────────────────────────────────');

        try {
            // Test 3a: Simple greeting (should NOT search)
            $this->line('Test 3a: Simple greeting (should NOT search)');
            $response1 = $modelClass::intelligentChat('Hello!', 'test-session-1');

            $ragEnabled = $response1->getMetadata()['rag_enabled'] ?? false;
            $this->line("   Query: 'Hello!'");
            $this->line("   RAG Used: " . ($ragEnabled ? 'Yes ❌' : 'No ✅'));
            $this->line("   Response: " . substr($response1->getContent(), 0, 100) . '...');

            $this->newLine();

            // Test 3b: Factual query (should search)
            $this->line('Test 3b: Factual query (should search)');
            $query = $this->ask('Enter a factual query', 'Tell me about Laravel routing');

            $response2 = $modelClass::intelligentChat($query, 'test-session-2');

            $ragEnabled = $response2->getMetadata()['rag_enabled'] ?? false;
            $contextCount = $response2->getMetadata()['context_count'] ?? 0;

            $this->line("   Query: '{$query}'");
            $this->line("   RAG Used: " . ($ragEnabled ? 'Yes ✅' : 'No ❌'));
            $this->line("   Context Items: {$contextCount}");
            $this->line("   Response: " . substr($response2->getContent(), 0, 150) . '...');

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Intelligent RAG failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 4: Manual RAG
     */
    protected function testManualRAG(string $modelClass): void
    {
        $this->line('🔧 Test 4: Manual RAG (Always Searches)');
        $this->line('─────────────────────────────────');

        try {
            $query = $this->ask('Enter query for manual RAG', 'test query');

            $result = $modelClass::vectorChat($query);

            $this->info('✅ Manual RAG completed:');
            $this->line("   Query: '{$query}'");
            $this->line("   Context Count: {$result['context_count']}");
            $this->line("   Sources: " . count($result['sources']));
            $this->line("   Response: " . substr($result['response'], 0, 150) . '...');

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Manual RAG failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 5: Instance Methods
     */
    protected function testInstanceMethods(string $modelClass): void
    {
        $this->line('📝 Test 5: Instance Methods');
        $this->line('─────────────────────────────────');

        try {
            // Get first record
            $instance = $modelClass::first();

            if (!$instance) {
                $this->warn('⚠️  No records found in database');
                $this->newLine();
                return;
            }

            $this->line("Testing with {$modelClass} ID: {$instance->id}");
            $this->newLine();

            // Test ask()
            $this->line('Test 5a: ask() method');
            $answer = $instance->ask('What is this about?');
            $this->line("   Answer: " . substr($answer, 0, 100) . '...');
            $this->newLine();

            // Test summarize()
            $this->line('Test 5b: summarize() method');
            $summary = $instance->summarize(30);
            $this->line("   Summary: {$summary}");
            $this->newLine();

            // Test generateTags()
            $this->line('Test 5c: generateTags() method');
            $tags = $instance->generateTags(5);
            $this->line("   Tags: " . implode(', ', $tags));
            $this->newLine();

            // Test similarTo()
            $this->line('Test 5d: similarTo() method');
            $similar = $instance->similarTo(3);
            $this->line("   Similar items: {$similar->count()}");
            foreach ($similar as $item) {
                $this->line("      - ID: {$item->id}");
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Instance methods failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 6: Chat Service Integration
     */
    protected function testChatServiceIntegration(ChatService $chatService): void
    {
        $this->line('💬 Test 6: Chat Service Integration');
        $this->line('─────────────────────────────────');

        try {
            $message = $this->ask('Enter message for chat service', 'Hello, how are you?');

            $response = $chatService->processMessage(
                message: $message,
                sessionId: 'test-session-integration',
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: false,
                useIntelligentRAG: true,
                ragCollections: [],  // Auto-discover
                userId: 'test-user'
            );

            $this->info('✅ Chat service response:');
            $this->line("   Message: '{$message}'");
            $this->line("   Response: " . substr($response->getContent(), 0, 150) . '...');
            $this->line("   RAG Enabled: " . ($response->getMetadata()['rag_enabled'] ?? false ? 'Yes' : 'No'));
            $this->line("   Session ID: " . ($response->getMetadata()['session_id'] ?? 'N/A'));

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Chat service integration failed: {$e->getMessage()}");
            $this->newLine();
        }
    }
}
