<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\RAG\IntelligentRAGService;
use LaravelAIEngine\Services\RAG\RAGCollectionDiscovery;
use LaravelAIEngine\Services\Vector\VectorAccessControl;
use LaravelAIEngine\Services\Vector\VectorSearchService;
use LaravelAIEngine\Services\Node\FederatedSearchService;

class TestIntelligentSearchCommand extends Command
{
    protected $signature = 'ai-engine:test-intelligent-search
                            {--query= : Test query (default: "Find Milk products")}
                            {--user-id= : User ID for testing}
                            {--collections= : Comma-separated collection classes}
                            {--skip-federated : Skip federated search tests}
                            {--skip-filters : Skip model filter tests}
                            {--skip-descriptions : Skip RAG description tests}';

    protected $description = 'Test intelligent collection selection, model-specific filters, and federated discovery';

    public function handle()
    {
        $this->info('🧪 Testing Intelligent Search System');
        $this->newLine();

        $testsPassed = 0;
        $testsFailed = 0;

        // Test 1: RAG Description Discovery
        if (!$this->option('skip-descriptions')) {
            $this->info('📋 Test 1: RAG Description Discovery');
            $this->line('─────────────────────────────────────');
            
            try {
                $discovery = app(RAGCollectionDiscovery::class);
                $collections = $discovery->discoverWithDescriptions(useCache: false);
                
                if (empty($collections)) {
                    $this->warn('⚠️  No collections discovered');
                    $testsFailed++;
                } else {
                    $collectionCount = count($collections);
                    $this->info("✅ Discovered {$collectionCount} collections");
                    
                    foreach ($collections as $collection) {
                        $hasDescription = !empty($collection['description']);
                        $nodeCount = is_array($collection['nodes']) ? count($collection['nodes']) : 0;
                        
                        $this->line("  • {$collection['display_name']}");
                        $this->line("    Class: {$collection['class']}");
                        $this->line("    Description: " . ($hasDescription ? '✅ Present' : '❌ Missing'));
                        $this->line("    Nodes: {$nodeCount}");
                        
                        if ($hasDescription) {
                            $desc = is_string($collection['description']) ? $collection['description'] : json_encode($collection['description']);
                            $this->line("    → " . substr($desc, 0, 80) . '...');
                        }
                        
                        // Show node details
                        if ($nodeCount > 0 && is_array($collection['nodes'])) {
                            foreach ($collection['nodes'] as $node) {
                                $nodeName = $node['node_name'] ?? 'Unknown';
                                $this->line("      Node: {$nodeName}");
                            }
                        }
                        
                        $this->newLine();
                    }
                    
                    $testsPassed++;
                }
            } catch (\Exception $e) {
                $this->error("❌ Failed: {$e->getMessage()}");
                if ($this->option('verbose')) {
                    $this->error("File: {$e->getFile()}:{$e->getLine()}");
                }
                $testsFailed++;
            }
            
            $this->newLine();
        }

        // Test 2: Model-Specific Filters
        if (!$this->option('skip-filters')) {
            $this->info('🔒 Test 2: Model-Specific Filters');
            $this->line('─────────────────────────────────────');
            
            try {
                $userId = $this->option('user-id') ?? 1;
                $accessControl = app(VectorAccessControl::class);
                
                // Test with different model classes
                $testModels = $this->getTestModels();
                
                foreach ($testModels as $modelClass) {
                    if (!class_exists($modelClass)) {
                        $this->warn("  ⚠️  Model not found: {$modelClass}");
                        continue;
                    }
                    
                    $this->line("  Testing: " . class_basename($modelClass));
                    
                    // Check if model has custom filters
                    $hasCustomFilters = method_exists($modelClass, 'getVectorSearchFilters');
                    $hasConfig = method_exists($modelClass, 'getVectorSearchConfig');
                    
                    $this->line("    Custom Filters: " . ($hasCustomFilters ? '✅' : '❌'));
                    $this->line("    Custom Config: " . ($hasConfig ? '✅' : '❌'));
                    
                    if ($hasCustomFilters) {
                        try {
                            $filters = $modelClass::getVectorSearchFilters($userId, []);
                            $this->line("    Filters Applied: " . json_encode($filters));
                        } catch (\Exception $e) {
                            $this->error("    ❌ Filter error: {$e->getMessage()}");
                        }
                    }
                    
                    if ($hasConfig) {
                        try {
                            $config = $modelClass::getVectorSearchConfig();
                            $this->line("    Config: " . json_encode($config));
                        } catch (\Exception $e) {
                            $this->error("    ❌ Config error: {$e->getMessage()}");
                        }
                    }
                    
                    $this->newLine();
                }
                
                $testsPassed++;
            } catch (\Exception $e) {
                $this->error("❌ Failed: {$e->getMessage()}");
                $testsFailed++;
            }
            
            $this->newLine();
        }

        // Test 3: Intelligent Query Analysis
        $this->info('🤖 Test 3: Intelligent Query Analysis');
        $this->line('─────────────────────────────────────');
        
        try {
            $query = $this->option('query') ?? 'Find Milk products';
            $userId = $this->option('user-id') ?? 1;
            
            $this->line("Query: \"{$query}\"");
            $this->line("User ID: {$userId}");
            $this->newLine();
            
            $rag = app(IntelligentRAGService::class);
            
            // Get available collections (or use auto-discovery if none specified)
            $collections = $this->option('collections') 
                ? array_map('trim', explode(',', $this->option('collections')))
                : [];
            
            if (empty($collections)) {
                $this->info("Using automatic federated collection discovery");
                $this->info("AI will intelligently select collections based on query");
            } else {
                $this->info("Testing with " . count($collections) . " specified collections:");
                foreach ($collections as $collection) {
                    $this->line("  • " . class_basename($collection));
                }
            }
            $this->newLine();
            
            // Enable debug mode
            config(['ai-engine.debug' => true]);
            
            $startTime = microtime(true);
            
            $response = $rag->processMessage(
                message: $query,
                sessionId: 'test_' . time(),
                availableCollections: $collections,
                conversationHistory: [],
                options: [],
                userId: $userId
            );
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->info("✅ Query processed successfully");
            $this->line("Duration: {$duration}ms");
            $this->newLine();
            
            $this->line("Response:");
            $this->line($response->getContent());
            $this->newLine();
            
            $metadata = $response->getMetadata();
            if (!empty($metadata['sources'])) {
                $this->line("Sources found: " . count($metadata['sources']));
            }
            
            $testsPassed++;
        } catch (\Exception $e) {
            $this->error("❌ Failed: {$e->getMessage()}");
            $this->error("Stack trace: " . $e->getTraceAsString());
            $testsFailed++;
        }
        
        $this->newLine();

        // Test 4: Federated Search
        if (!$this->option('skip-federated') && config('ai-engine.nodes.enabled', false)) {
            $this->info('🌐 Test 4: Federated Search');
            $this->line('─────────────────────────────────────');
            
            try {
                $federatedSearch = app(FederatedSearchService::class);
                $query = $this->option('query') ?? 'test query';
                $userId = $this->option('user-id') ?? 1;
                
                $collections = $this->getCollections();
                
                if (empty($collections)) {
                    $this->warn('⚠️  No collections for federated search');
                    $testsFailed++;
                } else {
                    $results = $federatedSearch->search(
                        query: $query,
                        nodeIds: null,
                        limit: 5,
                        options: [
                            'collections' => $collections,
                            'threshold' => 0.7,
                        ],
                        userId: $userId
                    );
                    
                    $this->info("✅ Federated search completed");
                    $this->line("Results count: " . ($results['count'] ?? 0));
                    $this->line("Node: " . ($results['node'] ?? 'unknown'));
                    
                    $testsPassed++;
                }
            } catch (\Exception $e) {
                $this->error("❌ Failed: {$e->getMessage()}");
                $testsFailed++;
            }
            
            $this->newLine();
        }

        // Summary
        $this->info('📊 Test Summary');
        $this->line('─────────────────────────────────────');
        $this->line("✅ Passed: {$testsPassed}");
        $this->line("❌ Failed: {$testsFailed}");
        $this->line("Total: " . ($testsPassed + $testsFailed));
        $this->newLine();

        if ($testsFailed === 0) {
            $this->info('🎉 All tests passed!');
            return 0;
        } else {
            $this->error('⚠️  Some tests failed. Check the output above for details.');
            return 1;
        }
    }

    protected function getCollections(): array
    {
        if ($this->option('collections')) {
            return array_map('trim', explode(',', $this->option('collections')));
        }

        // Try to discover collections
        try {
            $discovery = app(RAGCollectionDiscovery::class);
            return $discovery->discover(useCache: false, includeFederated: false);
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function getTestModels(): array
    {
        $models = [];
        
        // Common model names to test
        $commonModels = [
            'App\\Models\\Product',
            'App\\Models\\Document',
            'App\\Models\\Email',
            'App\\Models\\Article',
            'App\\Models\\Customer',
            'App\\Models\\User',
        ];
        
        foreach ($commonModels as $model) {
            if (class_exists($model)) {
                $models[] = $model;
            }
        }
        
        // If no common models, try to discover
        if (empty($models)) {
            try {
                $discovery = app(RAGCollectionDiscovery::class);
                $models = $discovery->discover(useCache: false, includeFederated: false);
            } catch (\Exception $e) {
                // Ignore
            }
        }
        
        return array_slice($models, 0, 5); // Limit to 5 for testing
    }
}
