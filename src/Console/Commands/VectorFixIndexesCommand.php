<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Vector\VectorDriverManager;

class VectorFixIndexesCommand extends Command
{
    protected $signature = 'vector:fix-indexes 
                            {collection? : Specific collection to fix (optional, fixes all if not provided)}
                            {--dry-run : Show what would be fixed without making changes}
                            {--list : List all collections and their index types}';

    protected $description = 'Auto-fix vector index type mismatches (e.g., keyword -> integer for ID fields)';

    public function handle(VectorDriverManager $driverManager): int
    {
        $driver = $driverManager->driver();
        
        if (!method_exists($driver, 'autoFixIndexTypes')) {
            $this->error('Current vector driver does not support auto-fix functionality.');
            return 1;
        }

        $collection = $this->argument('collection');
        $dryRun = $this->option('dry-run');
        $list = $this->option('list');

        // List mode - show all collections and their index types
        if ($list) {
            return $this->listCollections($driver);
        }

        if ($collection) {
            // Fix specific collection
            return $this->fixCollection($driver, $collection, $dryRun);
        }

        // Fix all collections
        return $this->fixAllCollections($driver, $dryRun);
    }

    protected function listCollections($driver): int
    {
        $this->info('📋 Vector Collections and Index Types');
        $this->line('═══════════════════════════════════════════════════════════════');

        try {
            $host = config('ai-engine.vector.drivers.qdrant.host');
            $apiKey = config('ai-engine.vector.drivers.qdrant.api_key');
            
            $client = new \GuzzleHttp\Client([
                'base_uri' => $host,
                'headers' => ['api-key' => $apiKey, 'Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);
            
            $response = $client->get('/collections');
            $data = json_decode($response->getBody()->getContents(), true);
            
            foreach ($data['result']['collections'] ?? [] as $col) {
                $name = $col['name'];
                $count = $driver->count($name);
                
                $this->newLine();
                $this->info("📦 {$name} ({$count} vectors)");
                
                $indexes = $driver->getExistingIndexesWithTypes($name);
                if (empty($indexes)) {
                    $this->warn('   No indexes');
                } else {
                    foreach ($indexes as $field => $type) {
                        // Use getFieldType which detects from actual data (handles UUID vs int)
                        $expectedType = $driver->getFieldType($name, $field);
                        $normalizedCurrent = $this->normalizeType($type);
                        $normalizedExpected = $this->normalizeType($expectedType);
                        
                        if ($normalizedCurrent !== $normalizedExpected) {
                            $this->error("   ⚠️  {$field}: {$type} (should be {$expectedType})");
                        } else {
                            $this->line("   ✓ {$field}: {$type}");
                        }
                    }
                }
            }
            
            $this->newLine();
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to list collections: ' . $e->getMessage());
            return 1;
        }
    }

    protected function fixCollection($driver, string $collection, bool $dryRun): int
    {
        $this->info("🔧 Fixing indexes for collection: {$collection}");
        
        if (!$driver->collectionExists($collection)) {
            $this->error("Collection '{$collection}' does not exist.");
            return 1;
        }

        // Get current and expected types
        $currentTypes = $driver->getExistingIndexesWithTypes($collection);
        
        if (empty($currentTypes)) {
            $this->warn('No indexes found in this collection.');
            return 0;
        }

        $toFix = [];
        foreach ($currentTypes as $field => $currentType) {
            // Use getFieldType which detects from actual data (handles UUID vs int)
            $expectedType = $driver->getFieldType($collection, $field);
            $normalizedCurrent = $this->normalizeType($currentType);
            $normalizedExpected = $this->normalizeType($expectedType);
            
            if ($normalizedCurrent !== $normalizedExpected) {
                $toFix[$field] = [
                    'current' => $currentType,
                    'expected' => $expectedType,
                ];
            }
        }

        if (empty($toFix)) {
            $this->info('✅ All indexes have correct types. Nothing to fix.');
            return 0;
        }

        $this->newLine();
        $this->warn('Found ' . count($toFix) . ' index(es) with type mismatches:');
        
        foreach ($toFix as $field => $types) {
            $this->line("  • {$field}: {$types['current']} → {$types['expected']}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('🔍 Dry run - no changes made.');
            return 0;
        }

        $this->newLine();
        if (!$this->confirm('Do you want to fix these indexes?', true)) {
            $this->info('Aborted.');
            return 0;
        }

        $fixed = $driver->autoFixIndexTypes($collection);
        
        if (!empty($fixed)) {
            $this->newLine();
            $this->info('✅ Fixed ' . count($fixed) . ' index(es):');
            foreach ($fixed as $field) {
                $this->line("  • {$field}");
            }
        }

        return 0;
    }

    protected function fixAllCollections($driver, bool $dryRun): int
    {
        $this->info('🔧 Scanning all collections for index type mismatches...');
        $this->newLine();

        try {
            $host = config('ai-engine.vector.drivers.qdrant.host');
            $apiKey = config('ai-engine.vector.drivers.qdrant.api_key');
            
            $client = new \GuzzleHttp\Client([
                'base_uri' => $host,
                'headers' => ['api-key' => $apiKey, 'Content-Type' => 'application/json'],
                'timeout' => 30,
            ]);
            
            $response = $client->get('/collections');
            $data = json_decode($response->getBody()->getContents(), true);
            
            $allToFix = [];
            
            foreach ($data['result']['collections'] ?? [] as $col) {
                $name = $col['name'];
                $currentTypes = $driver->getExistingIndexesWithTypes($name);
                
                foreach ($currentTypes as $field => $currentType) {
                    // Use getFieldType which detects from actual data (handles UUID vs int)
                    $expectedType = $driver->getFieldType($name, $field);
                    $normalizedCurrent = $this->normalizeType($currentType);
                    $normalizedExpected = $this->normalizeType($expectedType);
                    
                    if ($normalizedCurrent !== $normalizedExpected) {
                        if (!isset($allToFix[$name])) {
                            $allToFix[$name] = [];
                        }
                        $allToFix[$name][$field] = [
                            'current' => $currentType,
                            'expected' => $expectedType,
                        ];
                    }
                }
            }

            if (empty($allToFix)) {
                $this->info('✅ All collections have correct index types. Nothing to fix.');
                return 0;
            }

            $totalFixes = array_sum(array_map('count', $allToFix));
            $this->warn("Found {$totalFixes} index(es) to fix across " . count($allToFix) . " collection(s):");
            $this->newLine();

            foreach ($allToFix as $collection => $fields) {
                $this->info("📦 {$collection}:");
                foreach ($fields as $field => $types) {
                    $this->line("   • {$field}: {$types['current']} → {$types['expected']}");
                }
            }

            if ($dryRun) {
                $this->newLine();
                $this->info('🔍 Dry run - no changes made.');
                return 0;
            }

            $this->newLine();
            if (!$this->confirm('Do you want to fix all these indexes?', true)) {
                $this->info('Aborted.');
                return 0;
            }

            $this->newLine();
            $this->info('Fixing indexes...');
            
            $results = $driver->autoFixAllCollections();
            
            $totalFixed = array_sum(array_map('count', $results));
            
            $this->newLine();
            $this->info("✅ Fixed {$totalFixed} index(es) across " . count($results) . " collection(s).");

            return 0;
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        
        $typeMap = [
            'int' => 'integer',
            'int64' => 'integer',
            'int32' => 'integer',
            'float64' => 'float',
            'float32' => 'float',
            'str' => 'keyword',
            'string' => 'keyword',
            'text' => 'keyword',
            'boolean' => 'bool',
        ];
        
        return $typeMap[$type] ?? $type;
    }
}
