<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Node\NodeRegistryService;
use LaravelAIEngine\Services\Node\CircuitBreakerService;
use LaravelAIEngine\Models\AINode;

class MonitorNodesCommand extends Command
{
    protected $signature = 'ai-engine:monitor-nodes
                            {--interval=60 : Check interval in seconds}
                            {--auto-recover : Attempt auto-recovery}
                            {--once : Run once and exit}';
    
    protected $description = 'Monitor node health and attempt recovery';
    
    public function handle(
        NodeRegistryService $registry,
        CircuitBreakerService $circuitBreaker
    ) {
        $interval = (int) $this->option('interval');
        $autoRecover = $this->option('auto-recover');
        $once = $this->option('once');
        
        $this->info("🏥 Starting node health monitoring");
        $this->info("Interval: {$interval}s | Auto-recover: " . ($autoRecover ? 'Yes' : 'No'));
        $this->newLine();
        
        do {
            $nodes = AINode::all();
            
            if ($nodes->isEmpty()) {
                $this->warn('No nodes registered');
                if ($once) break;
                sleep($interval);
                continue;
            }
            
            $this->table(
                ['Node', 'Status', 'Health', 'Response Time', 'Failures'],
                $nodes->map(fn($node) => [
                    $node->name,
                    $node->status,
                    $node->isHealthy() ? '✅' : '❌',
                    $node->avg_response_time ? $node->avg_response_time . 'ms' : 'N/A',
                    $node->ping_failures,
                ])
            );
            
            foreach ($nodes as $node) {
                $this->checkNodeHealth($node, $registry, $circuitBreaker, $autoRecover);
            }
            
            $this->newLine();
            
            if (!$once) {
                $this->info("Next check in {$interval}s...");
                sleep($interval);
            }
            
        } while (!$once);
        
        return 0;
    }
    
    protected function checkNodeHealth(
        AINode $node,
        NodeRegistryService $registry,
        CircuitBreakerService $circuitBreaker,
        bool $autoRecover
    ): void {
        $healthy = $registry->ping($node);
        
        if ($healthy) {
            $this->line("✅ {$node->name}: Healthy");
        } else {
            $this->error("❌ {$node->name}: Unhealthy");
            
            if ($autoRecover) {
                $this->attemptRecovery($node, $registry, $circuitBreaker);
            }
        }
    }
    
    protected function attemptRecovery(
        AINode $node,
        NodeRegistryService $registry,
        CircuitBreakerService $circuitBreaker
    ): void {
        $this->warn("  Attempting recovery for {$node->name}...");
        
        sleep(5);
        
        if ($registry->ping($node)) {
            $this->info("  ✅ Recovery successful!");
            $circuitBreaker->reset($node);
        } else {
            $this->error("  ❌ Recovery failed");
        }
    }
}
