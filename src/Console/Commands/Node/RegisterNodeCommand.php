<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class RegisterNodeCommand extends Command
{
    protected $signature = 'ai-engine:node-register
                            {name : Node name}
                            {url : Node URL}
                            {--description= : Node description (what it does)}
                            {--type=child : Node type (master/child)}
                            {--capabilities=* : Node capabilities}
                            {--domains=* : Business domains (ecommerce, blog, crm)}
                            {--data-types=* : Data types (products, posts, customers)}
                            {--keywords=* : Search keywords}
                            {--weight=1 : Node weight for load balancing}';
    
    protected $description = 'Register a new node';
    
    public function handle(NodeRegistryService $registry)
    {
        $node = $registry->register([
            'name' => $this->argument('name'),
            'url' => $this->argument('url'),
            'description' => $this->option('description'),
            'type' => $this->option('type'),
            'capabilities' => $this->option('capabilities') ?: ['search', 'actions'],
            'domains' => $this->option('domains') ?: [],
            'data_types' => $this->option('data-types') ?: [],
            'keywords' => $this->option('keywords') ?: [],
            'weight' => (int) $this->option('weight'),
        ]);
        
        $this->info("✅ Node registered successfully!");
        $this->newLine();
        
        $this->table(
            ['ID', 'Name', 'URL', 'Type', 'API Key'],
            [[$node->id, $node->name, $node->url, $node->type, $node->api_key]]
        );
        
        $this->newLine();
        $this->warn("⚠️  Save this API key - it won't be shown again!");
        
        return 0;
    }
}
