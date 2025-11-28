<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\SchemaAnalyzer;
use LaravelAIEngine\Services\ModelAnalyzer;
use LaravelAIEngine\Services\Vector\VectorSearchService;

class TestVectorJourneyCommand extends Command
{
    protected $signature = 'ai-engine:test-vector-journey 
                            {model? : The model class to test (optional)}
                            {--quick : Skip confirmations and run automatically}';
    
    protected $description = 'Test the complete vector indexing journey from discovery to search';

    protected array $testResults = [];

    public function handle(
        SchemaAnalyzer $schemaAnalyzer,
        ModelAnalyzer $modelAnalyzer,
        VectorSearchService $vectorSearch
    ): int {
        $this->displayHeader();
        
        $modelClass = $this->argument('model');
        $quick = $this->option('quick');
        
        // Step 1: Discovery
        $this->step1_Discovery($schemaAnalyzer, $modelClass);
        
        if (!$modelClass) {
            $this->info('💡 Specify a model to continue: php artisan ai-engine:test-vector-journey "App\Models\YourModel"');
            return self::SUCCESS;
        }
        
        if (!class_exists($modelClass)) {
            $this->error("Model class not found: {$modelClass}");
            return self::FAILURE;
        }
        
        // Step 2: Analysis
        $this->step2_Analysis($modelClass, $schemaAnalyzer, $modelAnalyzer);
        
        // Step 3: Configuration
        $this->step3_Configuration($modelClass);
        
        // Step 4: Indexing
        if ($quick || $this->confirm('Proceed with indexing?', false)) {
            $this->step4_Indexing($modelClass, $vectorSearch);
        } else {
            $this->warn('⏭️  Skipping indexing step');
        }
        
        // Step 5: Search Test
        if ($quick || $this->confirm('Test vector search?', false)) {
            $this->step5_SearchTest($modelClass);
        } else {
            $this->warn('⏭️  Skipping search test');
        }
        
        // Step 6: RAG Test
        if ($quick || $this->confirm('Test RAG (Retrieval Augmented Generation)?', false)) {
            $this->step6_RAGTest($modelClass);
        } else {
            $this->warn('⏭️  Skipping RAG test');
        }
        
        // Display Summary
        $this->displaySummary();
        
        return self::SUCCESS;
    }
    
    protected function displayHeader(): void
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                                                            ║');
        $this->line('║        🚀 Vector Indexing Journey Test Suite 🚀           ║');
        $this->line('║                                                            ║');
        $this->line('║  Testing the complete flow from discovery to search       ║');
        $this->line('║                                                            ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
    }
    
    protected function step1_Discovery(SchemaAnalyzer $analyzer, ?string $modelClass): void
    {
        $this->stepHeader('Step 1: Model Discovery');
        
        try {
            $models = $analyzer->discoverVectorizableModels();
            
            $this->info("✓ Found " . count($models) . " vectorizable models");
            
            if (empty($models)) {
                $this->warn('No vectorizable models found!');
                $this->line('Add the Vectorizable trait to your models:');
                $this->line('  use LaravelAIEngine\Traits\Vectorizable;');
                $this->testResults['discovery'] = 'failed';
                return;
            }
            
            if (count($models) <= 10) {
                foreach ($models as $model) {
                    $this->line("  • " . class_basename($model));
                }
            } else {
                $this->line("  Showing first 10:");
                foreach (array_slice($models, 0, 10) as $model) {
                    $this->line("  • " . class_basename($model));
                }
                $this->line("  ... and " . (count($models) - 10) . " more");
            }
            
            $this->testResults['discovery'] = 'passed';
            $this->testResults['models_found'] = count($models);
            
        } catch (\Exception $e) {
            $this->error("✗ Discovery failed: {$e->getMessage()}");
            $this->testResults['discovery'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function step2_Analysis(
        string $modelClass,
        SchemaAnalyzer $schemaAnalyzer,
        ModelAnalyzer $modelAnalyzer
    ): void {
        $this->stepHeader('Step 2: Model Analysis');
        
        try {
            // Schema Analysis
            $this->line("📊 Analyzing schema for: " . class_basename($modelClass));
            $schemaAnalysis = $schemaAnalyzer->analyzeModel($modelClass);
            
            $textFields = $schemaAnalysis['text_fields'] ?? [];
            $relationships = $schemaAnalysis['relationships'] ?? [];
            
            $this->info("✓ Found " . count($textFields) . " text fields");
            $this->info("✓ Found " . count($relationships) . " relationships");
            
            // Model Analysis
            $this->line("🔍 Performing comprehensive analysis...");
            $analysis = $modelAnalyzer->analyze($modelClass);
            
            $recommendations = $analysis['recommendations'] ?? [];
            $this->info("✓ Generated " . count($recommendations) . " recommendations");
            
            // Display key recommendations
            foreach ($recommendations as $rec) {
                $icon = match($rec['type']) {
                    'success' => '✓',
                    'warning' => '⚠',
                    'info' => 'ℹ',
                    default => '•'
                };
                $this->line("  {$icon} {$rec['message']}");
            }
            
            $this->testResults['analysis'] = 'passed';
            $this->testResults['text_fields'] = count($textFields);
            $this->testResults['relationships'] = count($relationships);
            
        } catch (\Exception $e) {
            $this->error("✗ Analysis failed: {$e->getMessage()}");
            $this->testResults['analysis'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function step3_Configuration(string $modelClass): void
    {
        $this->stepHeader('Step 3: Configuration Check');
        
        try {
            $model = new $modelClass;
            
            // Check if Vectorizable trait is used
            $usesVectorizable = in_array(
                'LaravelAIEngine\Traits\Vectorizable',
                class_uses_recursive($model)
            );
            
            if (!$usesVectorizable) {
                $this->error("✗ Model doesn't use Vectorizable trait");
                $this->testResults['configuration'] = 'failed';
                return;
            }
            
            $this->info("✓ Vectorizable trait detected");
            
            // Check vectorizable fields
            if (property_exists($model, 'vectorizable') && !empty($model->vectorizable)) {
                $this->info("✓ Vectorizable fields configured: " . implode(', ', $model->vectorizable));
            } else {
                $this->warn("⚠ No vectorizable fields configured (will use defaults)");
            }
            
            // Check relationships
            if (property_exists($model, 'vectorRelationships') && !empty($model->vectorRelationships)) {
                $this->info("✓ Relationships configured: " . implode(', ', $model->vectorRelationships));
                
                if (property_exists($model, 'maxRelationshipDepth')) {
                    $this->info("✓ Relationship depth: {$model->maxRelationshipDepth}");
                }
            } else {
                $this->line("ℹ No relationships configured");
            }
            
            $this->testResults['configuration'] = 'passed';
            
        } catch (\Exception $e) {
            $this->error("✗ Configuration check failed: {$e->getMessage()}");
            $this->testResults['configuration'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function step4_Indexing(string $modelClass, VectorSearchService $vectorSearch): void
    {
        $this->stepHeader('Step 4: Vector Indexing');
        
        try {
            $count = $modelClass::count();
            
            if ($count === 0) {
                $this->warn("⚠ No records found to index");
                $this->testResults['indexing'] = 'skipped';
                return;
            }
            
            $this->info("Found {$count} records to index");
            
            // Index a small sample
            $sampleSize = min(5, $count);
            $this->line("Indexing {$sampleSize} sample records...");
            
            $models = $modelClass::limit($sampleSize)->get();
            $indexed = 0;
            $failed = 0;
            
            $bar = $this->output->createProgressBar($sampleSize);
            $bar->start();
            
            foreach ($models as $model) {
                try {
                    $vectorSearch->index($model);
                    $indexed++;
                } catch (\Exception $e) {
                    $failed++;
                }
                $bar->advance();
            }
            
            $bar->finish();
            $this->newLine();
            
            $this->info("✓ Successfully indexed {$indexed} records");
            
            if ($failed > 0) {
                $this->warn("⚠ Failed to index {$failed} records");
            }
            
            $this->testResults['indexing'] = $failed === 0 ? 'passed' : 'partial';
            $this->testResults['indexed_count'] = $indexed;
            
        } catch (\Exception $e) {
            $this->error("✗ Indexing failed: {$e->getMessage()}");
            $this->testResults['indexing'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function step5_SearchTest(string $modelClass): void
    {
        $this->stepHeader('Step 5: Vector Search Test');
        
        try {
            // Get a sample record to use for search
            $sample = $modelClass::first();
            
            if (!$sample) {
                $this->warn("⚠ No records available for search test");
                $this->testResults['search'] = 'skipped';
                return;
            }
            
            // Get search query from sample content
            $content = method_exists($sample, 'getVectorContent') 
                ? $sample->getVectorContent() 
                : '';
            
            if (empty($content)) {
                $this->warn("⚠ No content available for search test");
                $this->testResults['search'] = 'skipped';
                return;
            }
            
            // Use first few words as search query
            $words = explode(' ', $content);
            $query = implode(' ', array_slice($words, 0, 3));
            
            $this->line("Searching for: \"{$query}\"");
            
            // Perform search
            $results = $modelClass::vectorSearch($query, limit: 3);
            
            $this->info("✓ Search completed");
            $this->info("✓ Found " . $results->count() . " results");
            
            if ($results->count() > 0) {
                $this->line("Top results:");
                foreach ($results->take(3) as $index => $result) {
                    $score = $result->_vector_score ?? 'N/A';
                    $this->line("  " . ($index + 1) . ". ID: {$result->id} (Score: {$score})");
                }
            }
            
            $this->testResults['search'] = 'passed';
            $this->testResults['search_results'] = $results->count();
            
        } catch (\Exception $e) {
            $this->error("✗ Search test failed: {$e->getMessage()}");
            $this->testResults['search'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function step6_RAGTest(string $modelClass): void
    {
        $this->stepHeader('Step 6: RAG (Retrieval Augmented Generation) Test');
        
        try {
            // Get a sample for RAG test
            $sample = $modelClass::first();
            
            if (!$sample) {
                $this->warn("⚠ No records available for RAG test");
                $this->testResults['rag'] = 'skipped';
                return;
            }
            
            $content = method_exists($sample, 'getVectorContent') 
                ? $sample->getVectorContent() 
                : '';
            
            if (empty($content)) {
                $this->warn("⚠ No content available for RAG test");
                $this->testResults['rag'] = 'skipped';
                return;
            }
            
            // Create a test query
            $words = explode(' ', $content);
            $query = "Tell me about " . implode(' ', array_slice($words, 0, 2));
            
            $this->line("RAG Query: \"{$query}\"");
            
            // Test intelligent RAG
            if (method_exists($modelClass, 'intelligentChat')) {
                $this->line("Testing intelligent RAG...");
                
                $response = $modelClass::intelligentChat(
                    $query,
                    sessionId: 'test-journey-' . time()
                );
                
                $this->info("✓ RAG response generated");
                $this->line("Response preview: " . substr($response->content, 0, 100) . "...");
                
                $this->testResults['rag'] = 'passed';
            } else {
                $this->warn("⚠ intelligentChat method not available");
                $this->testResults['rag'] = 'skipped';
            }
            
        } catch (\Exception $e) {
            $this->error("✗ RAG test failed: {$e->getMessage()}");
            $this->testResults['rag'] = 'failed';
        }
        
        $this->newLine();
    }
    
    protected function stepHeader(string $title): void
    {
        $this->line('┌' . str_repeat('─', 58) . '┐');
        $this->line('│ ' . str_pad($title, 56) . ' │');
        $this->line('└' . str_repeat('─', 58) . '┘');
        $this->newLine();
    }
    
    protected function displaySummary(): void
    {
        $this->newLine();
        $this->line('╔════════════════════════════════════════════════════════════╗');
        $this->line('║                     TEST SUMMARY                           ║');
        $this->line('╚════════════════════════════════════════════════════════════╝');
        $this->newLine();
        
        $steps = [
            'discovery' => 'Model Discovery',
            'analysis' => 'Model Analysis',
            'configuration' => 'Configuration Check',
            'indexing' => 'Vector Indexing',
            'search' => 'Vector Search',
            'rag' => 'RAG Test',
        ];
        
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        
        foreach ($steps as $key => $label) {
            $status = $this->testResults[$key] ?? 'not_run';
            
            $icon = match($status) {
                'passed' => '<fg=green>✓</>',
                'failed' => '<fg=red>✗</>',
                'partial' => '<fg=yellow>⚠</>',
                'skipped' => '<fg=gray>⏭</>',
                default => '<fg=gray>-</>',
            };
            
            $statusText = match($status) {
                'passed' => '<fg=green>PASSED</>',
                'failed' => '<fg=red>FAILED</>',
                'partial' => '<fg=yellow>PARTIAL</>',
                'skipped' => '<fg=gray>SKIPPED</>',
                default => '<fg=gray>NOT RUN</>',
            };
            
            $this->line(sprintf('  %s %-30s %s', $icon, $label, $statusText));
            
            if ($status === 'passed' || $status === 'partial') $passed++;
            if ($status === 'failed') $failed++;
            if ($status === 'skipped') $skipped++;
        }
        
        $this->newLine();
        
        // Statistics
        if (isset($this->testResults['models_found'])) {
            $this->line("  Models Found: {$this->testResults['models_found']}");
        }
        if (isset($this->testResults['text_fields'])) {
            $this->line("  Text Fields: {$this->testResults['text_fields']}");
        }
        if (isset($this->testResults['relationships'])) {
            $this->line("  Relationships: {$this->testResults['relationships']}");
        }
        if (isset($this->testResults['indexed_count'])) {
            $this->line("  Records Indexed: {$this->testResults['indexed_count']}");
        }
        if (isset($this->testResults['search_results'])) {
            $this->line("  Search Results: {$this->testResults['search_results']}");
        }
        
        $this->newLine();
        
        // Overall result
        if ($failed > 0) {
            $this->error("❌ Some tests failed. Please review the errors above.");
        } elseif ($passed > 0) {
            $this->info("✅ All executed tests passed!");
        } else {
            $this->warn("⚠️  No tests were executed.");
        }
        
        $this->newLine();
        
        // Next steps
        $this->line("<fg=cyan>📚 Next Steps:</>");
        $this->line("  1. Review any warnings or failures above");
        $this->line("  2. Run: php artisan ai-engine:analyze-model \"YourModel\"");
        $this->line("  3. Run: php artisan ai-engine:vector-index \"YourModel\" --with-relationships");
        $this->line("  4. Test search: YourModel::vectorSearch('query')");
        $this->newLine();
    }
}
