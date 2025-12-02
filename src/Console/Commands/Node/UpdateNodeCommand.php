<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Models\AINode;

class UpdateNodeCommand extends Command
{
    protected $signature = 'ai-engine:node-update
                            {node : Node ID or slug}
                            {--url= : Update node URL}
                            {--name= : Update node name}
                            {--description= : Update node description}
                            {--type= : Update node type (master/child)}
                            {--status= : Update node status (active/inactive/maintenance/error)}
                            {--weight= : Update load balancing weight}
                            {--capabilities=* : Update capabilities}
                            {--domains=* : Update business domains}
                            {--data-types=* : Update data types}
                            {--keywords=* : Update search keywords}
                            {--regenerate-key : Regenerate API key}';
    
    protected $description = 'Update an existing node';
    
    public function handle()
    {
        $nodeIdentifier = $this->argument('node');
        
        // Find node by ID or slug
        $node = is_numeric($nodeIdentifier)
            ? AINode::find($nodeIdentifier)
            : AINode::where('slug', $nodeIdentifier)->first();
        
        if (!$node) {
            $this->error("Node not found: {$nodeIdentifier}");
            return 1;
        }
        
        $this->info("Updating node: {$node->name} (ID: {$node->id})");
        $this->newLine();
        
        $updates = [];
        $hasChanges = false;
        
        // Collect updates
        if ($url = $this->option('url')) {
            $updates['url'] = $url;
            $hasChanges = true;
            $this->line("✓ URL: {$url}");
        }
        
        if ($name = $this->option('name')) {
            $updates['name'] = $name;
            $updates['slug'] = \Str::slug($name);
            $hasChanges = true;
            $this->line("✓ Name: {$name}");
        }
        
        if ($description = $this->option('description')) {
            $updates['description'] = $description;
            $hasChanges = true;
            $this->line("✓ Description: {$description}");
        }
        
        if ($type = $this->option('type')) {
            if (!in_array($type, ['master', 'child'])) {
                $this->error("Invalid type. Must be 'master' or 'child'");
                return 1;
            }
            $updates['type'] = $type;
            $hasChanges = true;
            $this->line("✓ Type: {$type}");
        }
        
        if ($status = $this->option('status')) {
            if (!in_array($status, ['active', 'inactive', 'maintenance', 'error'])) {
                $this->error("Invalid status. Must be: active, inactive, maintenance, or error");
                return 1;
            }
            $updates['status'] = $status;
            $hasChanges = true;
            $this->line("✓ Status: {$status}");
        }
        
        if ($weight = $this->option('weight')) {
            $updates['weight'] = (int) $weight;
            $hasChanges = true;
            $this->line("✓ Weight: {$weight}");
        }
        
        if ($capabilities = $this->option('capabilities')) {
            $updates['capabilities'] = $capabilities;
            $hasChanges = true;
            $this->line("✓ Capabilities: " . implode(', ', $capabilities));
        }
        
        if ($domains = $this->option('domains')) {
            $updates['domains'] = $domains;
            $hasChanges = true;
            $this->line("✓ Domains: " . implode(', ', $domains));
        }
        
        if ($dataTypes = $this->option('data-types')) {
            $updates['data_types'] = $dataTypes;
            $hasChanges = true;
            $this->line("✓ Data Types: " . implode(', ', $dataTypes));
        }
        
        if ($keywords = $this->option('keywords')) {
            $updates['keywords'] = $keywords;
            $hasChanges = true;
            $this->line("✓ Keywords: " . implode(', ', $keywords));
        }
        
        // Regenerate API key if requested
        $newApiKey = null;
        if ($this->option('regenerate-key')) {
            $newApiKey = bin2hex(random_bytes(32));
            $updates['api_key'] = $newApiKey;
            $hasChanges = true;
            $this->line("✓ API Key: Regenerated");
        }
        
        if (!$hasChanges) {
            $this->warn('No changes specified. Use --help to see available options.');
            return 0;
        }
        
        // Confirm changes
        $this->newLine();
        if (!$this->confirm('Apply these changes?', true)) {
            $this->info('Update cancelled');
            return 0;
        }
        
        // Apply updates
        try {
            $node->update($updates);
            
            $this->newLine();
            $this->info('✅ Node updated successfully!');
            $this->newLine();
            
            // Show updated node info
            $this->table(
                ['Field', 'Value'],
                [
                    ['ID', $node->id],
                    ['Name', $node->name],
                    ['Slug', $node->slug],
                    ['URL', $node->url],
                    ['Type', $node->type],
                    ['Status', $node->status],
                    ['Weight', $node->weight],
                    ['Capabilities', implode(', ', $node->capabilities ?? [])],
                    ['Domains', implode(', ', $node->domains ?? [])],
                    ['Data Types', implode(', ', $node->data_types ?? [])],
                    ['Keywords', implode(', ', $node->keywords ?? [])],
                ]
            );
            
            if ($newApiKey) {
                $this->newLine();
                $this->warn('⚠️  New API Key: ' . $newApiKey);
                $this->warn('⚠️  Save this key - it won\'t be shown again!');
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to update node: ' . $e->getMessage());
            return 1;
        }
    }
}
