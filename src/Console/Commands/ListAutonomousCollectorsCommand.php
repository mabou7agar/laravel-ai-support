<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorRegistry;
use LaravelAIEngine\Services\DataCollector\AutonomousCollectorDiscoveryService;
use LaravelAIEngine\Models\AINode;

class ListAutonomousCollectorsCommand extends Command
{
    protected $signature = 'ai-engine:autonomous-collectors 
                            {--discover : Force re-discovery of collectors}
                            {--nodes : Show collectors from registered nodes}';

    protected $description = 'List all registered autonomous collectors';

    public function handle(): int
    {
        // Force discovery if requested
        if ($this->option('discover')) {
            $this->info('Discovering autonomous collectors...');
            $discoveryService = app(AutonomousCollectorDiscoveryService::class);
            $discoveryService->clearCache();
            $discoveryService->registerDiscoveredCollectors(useCache: false);
        }

        // Show local collectors
        $this->info('');
        $this->info('📦 Local Autonomous Collectors');
        $this->info('================================');
        
        $configs = AutonomousCollectorRegistry::getConfigs();
        
        if (empty($configs)) {
            $this->warn('No autonomous collectors registered.');
        } else {
            $rows = [];
            foreach ($configs as $name => $configData) {
                $config = $configData['config'];
                if ($config instanceof \Closure) {
                    $config = $config();
                }
                
                $rows[] = [
                    $name,
                    $config->goal ?? 'N/A',
                    $configData['description'] ?? $config->description ?? 'N/A',
                    count($config->tools ?? []) . ' tools',
                ];
            }
            
            $this->table(
                ['Name', 'Goal', 'Description', 'Tools'],
                $rows
            );
        }

        // Show node collectors if requested
        if ($this->option('nodes')) {
            $this->info('');
            $this->info('🌐 Node Autonomous Collectors');
            $this->info('==============================');
            
            $nodes = AINode::where('status', 'active')->get();
            
            if ($nodes->isEmpty()) {
                $this->warn('No active nodes found.');
            } else {
                foreach ($nodes as $node) {
                    $collectors = $node->autonomous_collectors ?? [];
                    $isHealthy = $node->isHealthy() ? '✅' : '❌';
                    
                    $this->info('');
                    $this->line("{$isHealthy} <comment>{$node->name}</comment> ({$node->slug})");
                    $this->line("   URL: {$node->url}");
                    $this->line("   Last Ping: " . ($node->last_ping_at ? $node->last_ping_at->diffForHumans() : 'never'));
                    
                    if (empty($collectors)) {
                        $this->line("   Collectors: <fg=yellow>None</>");
                    } else {
                        $this->line("   Collectors:");
                        foreach ($collectors as $collector) {
                            $name = $collector['name'] ?? 'unknown';
                            $goal = $collector['goal'] ?? 'N/A';
                            $this->line("     • <info>{$name}</info>: {$goal}");
                        }
                    }
                }
            }
        }

        $this->info('');
        return Command::SUCCESS;
    }
}
