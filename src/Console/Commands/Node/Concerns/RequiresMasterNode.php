<?php

namespace LaravelAIEngine\Console\Commands\Node\Concerns;

trait RequiresMasterNode
{
    /**
     * Check if this is a master node
     * Returns true if master, false if child (and shows error)
     */
    protected function ensureMasterNode(): bool
    {
        if (!config('ai-engine.nodes.is_master', true)) {
            $this->error('❌ This command is only available on master nodes!');
            $this->newLine();
            $this->info('💡 Child nodes cannot manage the node registry.');
            $this->info('   Set AI_ENGINE_IS_MASTER=true in .env to enable this command.');
            $this->newLine();
            $this->comment('Current configuration:');
            $this->line('  AI_ENGINE_IS_MASTER=' . (config('ai-engine.nodes.is_master') ? 'true' : 'false'));
            
            return false;
        }
        
        return true;
    }
}
