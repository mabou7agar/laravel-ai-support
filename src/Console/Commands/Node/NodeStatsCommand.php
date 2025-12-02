<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Console\Commands\Node\Concerns\RequiresMasterNode;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class NodeStatsCommand extends Command
{
    use RequiresMasterNode;
    protected $signature = 'ai-engine:node-stats';
    protected $description = 'Show node statistics';
    
    public function handle(NodeRegistryService $registry)
    {
        if (!$this->ensureMasterNode()) {
            return 1;
        }
        
        $stats = $registry->getStatistics();
        
        $this->info('📊 Node Statistics');
        $this->newLine();
        
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Nodes', $stats['total']],
                ['Active', $stats['active']],
                ['Inactive', $stats['inactive']],
                ['Error', $stats['error']],
                ['Healthy', $stats['healthy']],
                ['Avg Response Time', round($stats['avg_response_time'] ?? 0) . 'ms'],
            ]
        );
        
        if (!empty($stats['by_type'])) {
            $this->newLine();
            $this->info('By Type:');
            $this->table(
                ['Type', 'Count'],
                collect($stats['by_type'])->map(fn($count, $type) => [$type, $count])
            );
        }
        
        return 0;
    }
}
