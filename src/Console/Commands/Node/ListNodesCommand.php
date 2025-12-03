<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Models\AINode;
use LaravelAIEngine\Console\Commands\Node\Concerns\RequiresMasterNode;

class ListNodesCommand extends Command
{
    use RequiresMasterNode;
    protected $signature = 'ai-engine:node-list
                            {--status= : Filter by status}
                            {--type= : Filter by type}';
    
    protected $description = 'List all registered nodes';
    
    public function handle()
    {
        if (!$this->ensureMasterNode()) {
            return 1;
        }
        
        $query = AINode::query();
        
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }
        
        if ($type = $this->option('type')) {
            $query->where('type', $type);
        }
        
        $nodes = $query->get();
        
        if ($nodes->isEmpty()) {
            $this->warn('No nodes found');
            return 0;
        }
        
        $this->table(
            ['ID', 'Name', 'Type', 'Status', 'Health', 'Response Time', 'Last Ping'],
            $nodes->map(fn($node) => [
                $node->id,
                $node->name,
                $node->type,
                $node->status,
                $node->isHealthy() ? '✅' : '❌',
                $node->avg_response_time ? $node->avg_response_time . 'ms' : 'N/A',
                $node->last_ping_at?->diffForHumans() ?? 'Never',
            ])
        );
        
        return 0;
    }
}
