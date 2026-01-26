<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Node\NodeMetadataDiscovery;

class NodeDiscoverCommand extends Command
{
    protected $signature = 'ai:node:discover 
                            {--sync : Sync discovered metadata with master node}
                            {--json : Output as JSON}';

    protected $description = 'Discover node metadata from workflows, models, and capabilities';

    public function handle(): int
    {
        $discovery = new NodeMetadataDiscovery();
        $metadata = $discovery->discover();

        if ($this->option('json')) {
            $this->line(json_encode($metadata, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info('');
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║              NODE METADATA DISCOVERY                         ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->info('');

        // Description
        $this->line('<fg=cyan>Description:</>');
        $this->line('  ' . ($metadata['description'] ?: '(none discovered)'));
        $this->info('');

        // Capabilities
        $this->line('<fg=cyan>Capabilities:</>');
        if (!empty($metadata['capabilities'])) {
            foreach ($metadata['capabilities'] as $cap) {
                $this->line("  • {$cap}");
            }
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Domains
        $this->line('<fg=cyan>Domains:</>');
        if (!empty($metadata['domains'])) {
            $this->line('  ' . implode(', ', $metadata['domains']));
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Data Types
        $this->line('<fg=cyan>Data Types:</>');
        if (!empty($metadata['data_types'])) {
            $this->line('  ' . implode(', ', $metadata['data_types']));
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Keywords
        $this->line('<fg=cyan>Keywords:</>');
        if (!empty($metadata['keywords'])) {
            $this->line('  ' . implode(', ', array_slice($metadata['keywords'], 0, 15)));
            if (count($metadata['keywords']) > 15) {
                $this->line('  ... and ' . (count($metadata['keywords']) - 15) . ' more');
            }
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Collections (Vectorizable Models)
        $this->line('<fg=cyan>Collections (Vectorizable Models):</>');
        if (!empty($metadata['collections'])) {
            foreach ($metadata['collections'] as $collection) {
                $this->line("  • {$collection}");
            }
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Workflows
        $this->line('<fg=cyan>Workflows:</>');
        if (!empty($metadata['workflows'])) {
            foreach ($metadata['workflows'] as $workflow) {
                $this->line("  • {$workflow}");
            }
        } else {
            $this->line('  (none discovered)');
        }
        $this->info('');

        // Sync with master if requested
        if ($this->option('sync')) {
            $this->syncWithMaster($metadata);
        }

        return 0;
    }

    protected function syncWithMaster(array $metadata): void
    {
        $masterUrl = config('ai-engine.nodes.master_url');
        
        if (empty($masterUrl)) {
            $this->warn('No master URL configured. Set AI_ENGINE_MASTER_URL in .env');
            return;
        }

        if (config('ai-engine.nodes.is_master', true)) {
            $this->warn('This node is configured as master. Cannot sync with self.');
            return;
        }

        $this->info('Syncing metadata with master node...');
        
        try {
            $client = \LaravelAIEngine\Services\Node\NodeHttpClient::makeAuthenticated();
            
            $response = $client->post(rtrim($masterUrl, '/') . '/api/ai-engine/node/register', [
                'name' => config('app.name'),
                'url' => config('app.url'),
                'description' => $metadata['description'],
                'capabilities' => $metadata['capabilities'],
                'domains' => $metadata['domains'],
                'data_types' => $metadata['data_types'],
                'keywords' => $metadata['keywords'],
                'collections' => $metadata['collections'],
            ]);

            if ($response->successful()) {
                $this->info('✓ Metadata synced successfully');
            } else {
                $this->error('Failed to sync: ' . $response->status());
            }
        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
        }
    }
}
