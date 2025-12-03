<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class NodeLogsCommand extends Command
{
    protected $signature = 'ai-engine:node-logs
                            {--lines=50 : Number of lines to show}
                            {--follow : Follow the log file}
                            {--errors-only : Show only errors}
                            {--node= : Filter by node slug}';
    
    protected $description = 'View node connection logs';
    
    public function handle()
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            $this->error('Log file not found: ' . $logPath);
            return 1;
        }
        
        if ($this->option('follow')) {
            $this->followLogs($logPath);
        } else {
            $this->showLogs($logPath);
        }
        
        return 0;
    }
    
    protected function showLogs(string $logPath): void
    {
        $lines = (int) $this->option('lines');
        $errorsOnly = $this->option('errors-only');
        $nodeSlug = $this->option('node');
        
        // Read last N lines
        $command = "tail -n {$lines} " . escapeshellarg($logPath);
        $output = shell_exec($command);
        
        if (!$output) {
            $this->warn('No logs found');
            return;
        }
        
        $logLines = explode("\n", $output);
        $filtered = [];
        
        foreach ($logLines as $line) {
            // Filter node-related logs
            if (!str_contains($line, 'Node ping') && 
                !str_contains($line, 'node_id') && 
                !str_contains($line, 'Circuit breaker')) {
                continue;
            }
            
            // Filter by error level if requested
            if ($errorsOnly && !str_contains($line, '.ERROR')) {
                continue;
            }
            
            // Filter by node slug if requested
            if ($nodeSlug && !str_contains($line, $nodeSlug)) {
                continue;
            }
            
            $filtered[] = $line;
        }
        
        if (empty($filtered)) {
            $this->warn('No matching logs found');
            return;
        }
        
        $this->info('📋 Node Connection Logs (last ' . count($filtered) . ' entries)');
        $this->info(str_repeat('=', 80));
        $this->newLine();
        
        foreach ($filtered as $line) {
            $this->formatLogLine($line);
        }
    }
    
    protected function followLogs(string $logPath): void
    {
        $this->info('📋 Following node connection logs (Ctrl+C to stop)');
        $this->info(str_repeat('=', 80));
        $this->newLine();
        
        $nodeSlug = $this->option('node');
        $errorsOnly = $this->option('errors-only');
        
        $handle = popen("tail -f " . escapeshellarg($logPath), 'r');
        
        while (!feof($handle)) {
            $line = fgets($handle);
            
            if (!$line) {
                continue;
            }
            
            // Filter node-related logs
            if (!str_contains($line, 'Node ping') && 
                !str_contains($line, 'node_id') && 
                !str_contains($line, 'Circuit breaker')) {
                continue;
            }
            
            // Filter by error level if requested
            if ($errorsOnly && !str_contains($line, '.ERROR')) {
                continue;
            }
            
            // Filter by node slug if requested
            if ($nodeSlug && !str_contains($line, $nodeSlug)) {
                continue;
            }
            
            $this->formatLogLine($line);
        }
        
        pclose($handle);
    }
    
    protected function formatLogLine(string $line): void
    {
        if (str_contains($line, '.ERROR')) {
            $this->error('❌ ' . $line);
        } elseif (str_contains($line, '.WARNING')) {
            $this->warn('⚠️  ' . $line);
        } elseif (str_contains($line, '.INFO')) {
            $this->info('ℹ️  ' . $line);
        } else {
            $this->line($line);
        }
    }
}
