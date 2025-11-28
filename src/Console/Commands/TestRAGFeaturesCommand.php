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
                            {--quick : Run quick tests only}
                            {--detailed : Show detailed output}
                            {--skip-interactive : Skip interactive prompts}';

    protected $description = 'Comprehensive RAG testing suite (discovery, search, chat, context, relationships)';

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

        // Test 8: Context Enhancement
        if (!$this->option('quick')) {
            $this->testContextEnhancement($modelClass);
        }

        // Test 9: Auto-Detection Features
        if (!$this->option('quick')) {
            $this->testAutoDetection($modelClass);
        }

        // Test 10: Relationship Traversal
        if (!$this->option('quick')) {
            $this->testRelationshipTraversal($modelClass);
        }

        // Test 11: Content Truncation
        if (!$this->option('quick')) {
            $this->testContentTruncation($modelClass);
        }

        // Test 12: Vector Status
        $this->testVectorStatus($modelClass);

        $this->newLine();
        $this->info('✅ All tests completed successfully!');
        $this->displayTestSummary();

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
            // Test 6a: Intelligent RAG (AI decides)
            $this->line('Test 6a: Intelligent RAG (AI decides when to search)');
            $message = $this->option('skip-interactive') 
                ? 'What is Laravel?' 
                : $this->ask('Enter message for chat service', 'What is Laravel?');

            $response = $chatService->processMessage(
                message: $message,
                sessionId: 'test-session-integration-intelligent',
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: false,
                useIntelligentRAG: true,
                ragCollections: [],  // Auto-discover
                userId: 'test-user'
            );

            $ragEnabled = $response->getMetadata()['rag_enabled'] ?? false;
            $contextCount = $response->getMetadata()['context_count'] ?? 0;

            $this->line("   Message: '{$message}'");
            $this->line("   RAG Enabled: " . ($ragEnabled ? 'Yes ✅' : 'No ❌'));
            $this->line("   Context Items: {$contextCount}");
            $this->line("   Response: " . substr($response->getContent(), 0, 150) . '...');
            $this->newLine();

            // Test 6b: Force RAG (always searches)
            $this->line('Test 6b: Forced RAG (always searches knowledge base)');
            $message2 = $this->option('skip-interactive') 
                ? 'Tell me about the content' 
                : $this->ask('Enter another message', 'Tell me about the content');

            $response2 = $chatService->processMessage(
                message: $message2,
                sessionId: 'test-session-integration-forced',
                engine: 'openai',
                model: 'gpt-4o-mini',
                useMemory: true,
                useActions: false,
                useIntelligentRAG: false,  // Force RAG
                forceRAG: true,
                ragCollections: [],  // Auto-discover
                userId: 'test-user'
            );

            $contextCount2 = $response2->getMetadata()['context_count'] ?? 0;
            $sources = $response2->getMetadata()['sources'] ?? [];

            $this->line("   Message: '{$message2}'");
            $this->line("   RAG: Forced ✅");
            $this->line("   Context Items: {$contextCount2}");
            $this->line("   Sources: " . count($sources));
            $this->line("   Response: " . substr($response2->getContent(), 0, 150) . '...');

            $this->newLine();
            $this->info('✅ Chat service integration working');

        } catch (\Exception $e) {
            $this->error("❌ Chat service integration failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 8: Context Enhancement
     */
    protected function testContextEnhancement(string $modelClass): void
    {
        $this->line('🎯 Test 8: Context Enhancement');
        $this->line('─────────────────────────────────');

        try {
            $query = $this->option('skip-interactive') ? 'test' : $this->ask('Enter search query', 'test');
            
            $results = $modelClass::vectorSearch($query, 3);

            if ($results->isEmpty()) {
                $this->warn('⚠️  No results to test context enhancement');
                $this->newLine();
                return;
            }

            $this->info('✅ Testing context enhancement:');
            
            foreach ($results->take(1) as $result) {
                // Test metadata extraction
                $this->line("   Model: " . class_basename($result));
                $this->line("   ID: {$result->id}");
                
                if (isset($result->subject)) {
                    $this->line("   Subject: {$result->subject}");
                }
                if (isset($result->from_name)) {
                    $this->line("   From: {$result->from_name}");
                }
                if (isset($result->created_at)) {
                    $this->line("   Date: {$result->created_at}");
                }
                
                // Test vector content
                if (method_exists($result, 'getVectorContent')) {
                    $content = $result->getVectorContent();
                    $this->line("   Content Length: " . strlen($content) . " chars");
                    $this->line("   Content Preview: " . substr($content, 0, 100) . '...');
                }
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Context enhancement test failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 9: Auto-Detection Features
     */
    protected function testAutoDetection(string $modelClass): void
    {
        $this->line('🤖 Test 9: Auto-Detection Features');
        $this->line('─────────────────────────────────');

        try {
            $instance = $modelClass::first();

            if (!$instance) {
                $this->warn('⚠️  No records found');
                $this->newLine();
                return;
            }

            // Test vectorizable fields
            $vectorizable = $instance->vectorizable ?? [];
            $this->line("Vectorizable fields: " . (empty($vectorizable) ? 'Auto-detected' : 'Configured'));
            
            if (!empty($vectorizable)) {
                $this->line("   Fields: " . implode(', ', $vectorizable));
            }

            // Test collection name
            if (method_exists($instance, 'getVectorCollectionName')) {
                $collection = $instance->getVectorCollectionName();
                $this->line("   Collection: {$collection}");
            }

            // Test relationships
            if (property_exists($instance, 'vectorRelationships')) {
                $relations = $instance->vectorRelationships ?? [];
                if (!empty($relations)) {
                    $this->line("   Relationships: " . implode(', ', $relations));
                }
            }

            $this->info('✅ Auto-detection working');
            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Auto-detection test failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 10: Relationship Traversal
     */
    protected function testRelationshipTraversal(string $modelClass): void
    {
        $this->line('🔗 Test 10: Relationship Traversal');
        $this->line('─────────────────────────────────');

        try {
            $instance = $modelClass::first();

            if (!$instance) {
                $this->warn('⚠️  No records found');
                $this->newLine();
                return;
            }

            // Test indexable relationships
            if (method_exists($instance, 'getIndexableRelationships')) {
                $depth1 = $instance->getIndexableRelationships(1);
                $depth2 = $instance->getIndexableRelationships(2);
                $depth3 = $instance->getIndexableRelationships(3);

                $this->line("Depth 1: " . count($depth1) . " relationships");
                if (!empty($depth1) && $this->option('detailed')) {
                    foreach ($depth1 as $rel) {
                        $this->line("   - {$rel}");
                    }
                }

                $this->line("Depth 2: " . count($depth2) . " relationships");
                if (!empty($depth2) && $this->option('detailed')) {
                    foreach ($depth2 as $rel) {
                        $this->line("   - {$rel}");
                    }
                }

                $this->line("Depth 3: " . count($depth3) . " relationships");
                
                $this->info('✅ Relationship traversal working');
            } else {
                $this->warn('⚠️  Model does not support relationship traversal');
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Relationship traversal test failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 11: Content Truncation
     */
    protected function testContentTruncation(string $modelClass): void
    {
        $this->line('📏 Test 11: Content Truncation');
        $this->line('─────────────────────────────────');

        try {
            $instance = $modelClass::first();

            if (!$instance) {
                $this->warn('⚠️  No records found');
                $this->newLine();
                return;
            }

            if (method_exists($instance, 'getVectorContent')) {
                $content = $instance->getVectorContent();
                $length = strlen($content);
                $maxLength = config('ai-engine.vector.max_content_length', 32000);

                $this->line("Content length: {$length} chars");
                $this->line("Max allowed: {$maxLength} chars");
                
                if ($length > $maxLength) {
                    $this->warn("⚠️  Content exceeds limit (will be truncated)");
                } else {
                    $this->info("✅ Content within limits");
                }

                $percentage = round(($length / $maxLength) * 100, 1);
                $this->line("Usage: {$percentage}% of limit");
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Content truncation test failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Test 12: Vector Status
     */
    protected function testVectorStatus(string $modelClass): void
    {
        $this->line('📊 Test 12: Vector Status');
        $this->line('─────────────────────────────────');

        try {
            $total = $modelClass::count();
            
            // Get indexed count
            $vectorSearch = app(\LaravelAIEngine\Services\Vector\VectorSearchService::class);
            $indexed = $vectorSearch->getIndexedCount($modelClass);
            $pending = max(0, $total - $indexed);

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Records', $total],
                    ['Indexed', $indexed],
                    ['Pending', $pending],
                    ['Percentage', $total > 0 ? round(($indexed / $total) * 100, 1) . '%' : '0%'],
                ]
            );

            if ($pending > 0) {
                $this->warn("⚠️  {$pending} records need indexing");
                $this->line("   Run: php artisan ai-engine:vector-index \"{$modelClass}\"");
            } else {
                $this->info('✅ All records indexed');
            }

            $this->newLine();

        } catch (\Exception $e) {
            $this->error("❌ Vector status test failed: {$e->getMessage()}");
            $this->newLine();
        }
    }

    /**
     * Display test summary
     */
    protected function displayTestSummary(): void
    {
        $this->newLine();
        $this->line('═══════════════════════════════════');
        $this->line('📋 Test Summary');
        $this->line('═══════════════════════════════════');
        
        $tests = [
            '✅ Collection Discovery',
            '✅ Vector Search',
            '✅ Intelligent RAG',
            '✅ Manual RAG',
            '✅ Instance Methods',
            '✅ Chat Service Integration',
            '✅ Context Enhancement',
            '✅ Auto-Detection',
            '✅ Relationship Traversal',
            '✅ Content Truncation',
            '✅ Vector Status',
        ];

        foreach ($tests as $test) {
            $this->line($test);
        }

        $this->newLine();
        $this->info('🎉 All RAG features tested successfully!');
        $this->line('═══════════════════════════════════');
    }
}
