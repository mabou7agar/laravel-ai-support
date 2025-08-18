<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Streaming\WebSocketManager;

class StreamingServerCommand extends Command
{
    protected $signature = 'ai-engine:streaming-server 
                            {action : Action to perform (start, stop, status, stats)}
                            {--host=0.0.0.0 : Server host}
                            {--port=8080 : Server port}
                            {--max-connections=1000 : Maximum connections}';

    protected $description = 'Manage AI Engine WebSocket streaming server';

    public function handle(WebSocketManager $webSocketManager): int
    {
        $action = $this->argument('action');

        try {
            switch ($action) {
                case 'start':
                    return $this->startServer($webSocketManager);
                
                case 'stop':
                    return $this->stopServer($webSocketManager);
                
                case 'status':
                    return $this->showStatus($webSocketManager);
                
                case 'stats':
                    return $this->showStats($webSocketManager);
                
                default:
                    $this->error("Unknown action: {$action}");
                    $this->info("Available actions: start, stop, status, stats");
                    return 1;
            }
        } catch (\Exception $e) {
            $this->error("Failed to execute action: {$e->getMessage()}");
            return 1;
        }
    }

    protected function startServer(WebSocketManager $webSocketManager): int
    {
        $config = [
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'max_connections' => $this->option('max-connections'),
        ];

        $this->info("Starting WebSocket server on {$config['host']}:{$config['port']}...");

        if ($webSocketManager->startServer($config)) {
            $this->info("✅ WebSocket server started successfully!");
            $this->info("Server is listening for connections...");
            $this->info("Press Ctrl+C to stop the server.");
            
            // Keep the command running
            while (true) {
                sleep(1);
                // Check if server is still running
                $status = $webSocketManager->getServerStatus();
                if (!$status['running']) {
                    $this->error("Server stopped unexpectedly.");
                    return 1;
                }
            }
        } else {
            $this->error("❌ Failed to start WebSocket server.");
            return 1;
        }
    }

    protected function stopServer(WebSocketManager $webSocketManager): int
    {
        $this->info("Stopping WebSocket server...");

        if ($webSocketManager->stopServer()) {
            $this->info("✅ WebSocket server stopped successfully!");
            return 0;
        } else {
            $this->error("❌ Failed to stop WebSocket server.");
            return 1;
        }
    }

    protected function showStatus(WebSocketManager $webSocketManager): int
    {
        $this->info('=== WebSocket Server Status ===');
        $this->newLine();

        $status = $webSocketManager->getServerStatus();

        $runningColor = $status['running'] ? 'green' : 'red';
        $runningText = $status['running'] ? 'Running' : 'Stopped';

        $this->table(
            ['Property', 'Value'],
            [
                ['Status', "<fg={$runningColor}>{$runningText}</>"],
                ['Host', $status['host'] ?? 'N/A'],
                ['Port', $status['port'] ?? 'N/A'],
                ['Start Time', $status['start_time'] ?? 'N/A'],
                ['Uptime', $this->formatUptime($status['uptime'] ?? 0)],
            ]
        );

        return 0;
    }

    protected function showStats(WebSocketManager $webSocketManager): int
    {
        $this->info('=== WebSocket Server Statistics ===');
        $this->newLine();

        $stats = $webSocketManager->getStats();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Active Connections', number_format($stats['active_connections'] ?? 0)],
                ['Total Sessions', number_format($stats['total_sessions'] ?? 0)],
                ['Messages Sent', number_format($stats['messages_sent'] ?? 0)],
                ['Messages Received', number_format($stats['messages_received'] ?? 0)],
                ['Bytes Sent', $this->formatBytes($stats['bytes_sent'] ?? 0)],
                ['Bytes Received', $this->formatBytes($stats['bytes_received'] ?? 0)],
                ['Uptime', $this->formatUptime($stats['uptime'] ?? 0)],
                ['Avg Response Time', number_format($stats['avg_response_time'] ?? 0, 2) . 'ms'],
            ]
        );

        if (isset($stats['connections_by_session'])) {
            $this->newLine();
            $this->info('Active Sessions:');
            
            $sessionData = [];
            foreach ($stats['connections_by_session'] as $sessionId => $connectionCount) {
                $sessionData[] = [$sessionId, $connectionCount];
            }

            if (!empty($sessionData)) {
                $this->table(['Session ID', 'Connections'], $sessionData);
            } else {
                $this->line('No active sessions.');
            }
        }

        return 0;
    }

    protected function formatUptime(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m " . ($seconds % 60) . "s";
        }

        $hours = floor($minutes / 60);
        if ($hours < 24) {
            return "{$hours}h " . ($minutes % 60) . "m";
        }

        $days = floor($hours / 24);
        return "{$days}d " . ($hours % 24) . "h";
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $factor = floor(log($bytes, 1024));
        
        return round($bytes / pow(1024, $factor), 2) . ' ' . $units[$factor];
    }
}
