<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class PingNodesCommand extends Command
{
    protected $signature = 'ai-engine:node-ping {--all}';
    protected $description = 'Ping nodes to check health';
    
    public function handle(NodeRegistryService $registry)
    {
        $this->info('Pinging nodes...');
        
        $results = $registry->pingAll();
        
        $this->table(
            ['Node', 'Status', 'Result'],
            collect($results)->map(fn($result, $slug) => [
                $slug,
                $result['status'],
                $result['success'] ? '✅ Success' : '❌ Failed',
            ])
        );
        
        return 0;
    }
}
