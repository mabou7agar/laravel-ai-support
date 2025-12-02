<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeHttpClient;
use Illuminate\Support\Facades\Log;

class DiscoverCollectionsCommand extends Command
{
    protected $signature = 'ai-engine:discover-collections 
                            {--node= : Specific node slug to discover from}
                            {--update : Update node collections in database}
                            {--json : Output as JSON}';
    
    protected $description = 'Discover available collections from all nodes';
    
    public function handle()
    {
        $this->info('🔍 Discovering Collections from Nodes...');
        $this->newLine();
        
        // Get nodes to discover from
        $nodes = $this->option('node')
            ? AINode::where('slug', $this->option('node'))->get()
            : AINode::where('status', 'active')->get();
        
        if ($nodes->isEmpty()) {
            $this->error('No nodes found!');
            return 1;
        }
        
        $allCollections = [];
        $updateDatabase = $this->option('update');
        
        foreach ($nodes as $node) {
            $this->info("📡 Discovering from: {$node->name}");
            
            try {
                $collections = $this->discoverFromNode($node);
                
                if (empty($collections)) {
                    $this->warn("  ⚠️  No collections found");
                    continue;
                }
                
                $this->info("  ✅ Found " . count($collections) . " collections");
                
                // Store collections for this node
                $allCollections[$node->slug] = [
                    'node' => $node->name,
                    'url' => $node->url,
                    'collections' => $collections,
                ];
                
                // Update database if requested
                if ($updateDatabase) {
                    $node->update([
                        'collections' => array_column($collections, 'class'),
                    ]);
                    $this->info("  💾 Updated database");
                }
                
                // Show collections
                if (!$this->option('json')) {
                    foreach ($collections as $collection) {
                        $this->line("     - {$collection['class']} ({$collection['table']})");
                    }
                }
                
            } catch (\Exception $e) {
                $this->error("  ❌ Failed: " . $e->getMessage());
                Log::channel('ai-engine')->error('Collection discovery failed', [
                    'node' => $node->slug,
                    'error' => $e->getMessage(),
                ]);
            }
            
            $this->newLine();
        }
        
        // Output JSON if requested
        if ($this->option('json')) {
            $this->line(json_encode($allCollections, JSON_PRETTY_PRINT));
            return 0;
        }
        
        // Summary
        $this->info('📊 Summary:');
        $this->table(
            ['Node', 'Collections Count'],
            collect($allCollections)->map(function ($data, $slug) {
                return [
                    $data['node'],
                    count($data['collections']),
                ];
            })->values()->toArray()
        );
        
        // Show how to use
        $this->newLine();
        $this->info('💡 Usage in Code:');
        $this->line('');
        $this->line('// Get all available collections');
        $this->line('$collections = [];');
        foreach ($allCollections as $nodeData) {
            foreach ($nodeData['collections'] as $collection) {
                $this->line("$collections[] = '{$collection['class']}';");
            }
        }
        $this->line('');
        $this->line('// Use in RAG');
        $this->line('$response = $rag->processMessage(');
        $this->line('    message: "Your query",');
        $this->line('    sessionId: "session-123",');
        $this->line('    availableCollections: $collections,');
        $this->line('    options: []');
        $this->line(');');
        
        return 0;
    }
    
    /**
     * Discover collections from a specific node
     */
    protected function discoverFromNode(AINode $node): array
    {
        try {
            $response = NodeHttpClient::make()
                ->get($node->url . '/api/ai-engine/collections');
            
            if (!$response->successful()) {
                throw new \Exception("HTTP {$response->status()}: " . $response->body());
            }
            
            $data = $response->json();
            
            return $data['collections'] ?? [];
            
        } catch (\Exception $e) {
            throw new \Exception("Failed to discover collections: " . $e->getMessage());
        }
    }
}
