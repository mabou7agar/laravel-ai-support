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
                            {--nodes : Show collectors from registered nodes}
                            {--local-only : Discover local collectors only}
                            {--remote-only : Discover remote collectors only}';

    protected $description = 'List all registered autonomous collectors';

    public function handle(): int
    {
        $discoveryService = app(AutonomousCollectorDiscoveryService::class);
        
        // Force discovery if requested
        if ($this->option('discover')) {
            $this->info('🔍 Discovering autonomous collectors...');
            $discoveryService->clearCache();
            
            $includeRemote = !$this->option('local-only');
            $discovered = $discoveryService->discoverCollectors(useCache: false, includeRemote: $includeRemote);
            
            $this->info("✅ Discovered " . count($discovered) . " collectors");
            $this->newLine();
            
            // Show discovered collectors by source
            $localCount = count(array_filter($discovered, fn($c) => ($c['source'] ?? 'local') === 'local'));
            $remoteCount = count(array_filter($discovered, fn($c) => ($c['source'] ?? 'local') === 'remote'));
            
            $this->line("   Local: {$localCount}");
            $this->line("   Remote: {$remoteCount}");
            $this->newLine();
            
            // Show detailed breakdown
            if ($this->option('remote-only') || !$this->option('local-only')) {
                $this->showDiscoveredCollectors($discovered);
            }
            
            // Register discovered collectors
            $discoveryService->registerDiscoveredCollectors(useCache: false);
        }

        // Show local collectors
        $this->info('📦 Registered Autonomous Collectors');
        $this->info('====================================');
        
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

    /**
     * Show discovered collectors with detailed information
     */
    protected function showDiscoveredCollectors(array $discovered): void
    {
        $this->info('📋 Discovered Collectors Details');
        $this->info('=================================');
        
        $rows = [];
        foreach ($discovered as $name => $data) {
            $source = $data['source'] ?? 'local';
            $sourceLabel = $source === 'local' ? '🏠 Local' : '🌐 Remote';
            
            if ($source === 'remote') {
                $sourceLabel .= " ({$data['node_name']})";
            }
            
            $rows[] = [
                $name,
                $data['description'] ?? 'N/A',
                $data['priority'] ?? 0,
                $sourceLabel,
            ];
        }
        
        $this->table(
            ['Name', 'Description', 'Priority', 'Source'],
            $rows
        );
        $this->newLine();
    }
}
