<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Console\Commands\Node\Concerns\RequiresMasterNode;

class PingNodesCommand extends Command
{
    use RequiresMasterNode;
    protected $signature = 'ai-engine:node-ping {--all}';
    protected $description = 'Ping nodes to check health';
    
    public function handle(NodeRegistryService $registry)
    {
        if (!$this->ensureMasterNode()) {
            return 1;
        }
        
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
