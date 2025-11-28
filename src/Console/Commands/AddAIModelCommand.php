<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIModelRegistry;

class AddAIModelCommand extends Command
{
    protected $signature = 'ai-engine:add-model
                            {model-id : Model identifier (e.g., gpt-5, claude-4)}
                            {--provider= : Provider name (openai, anthropic, google)}
                            {--name= : Display name}
                            {--description= : Model description}
                            {--interactive : Interactive mode}';

    protected $description = 'Add a new AI model to the registry';

    public function handle(AIModelRegistry $registry): int
    {
        $modelId = $this->argument('model-id');

        if ($registry->getModel($modelId)) {
            $this->error("❌ Model '{$modelId}' already exists!");
            return self::FAILURE;
        }

        $data = $this->option('interactive') 
            ? $this->collectInteractive($modelId)
            : $this->collectFromOptions($modelId);

        try {
            $model = $registry->registerModel($data);
            
            $this->info("✅ Model '{$model->name}' added successfully!");
            $this->newLine();
            
            $this->displayModelInfo($model);
            
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Failed to add model: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    protected function collectInteractive(string $modelId): array
    {
        $this->info("🤖 Adding new AI model: {$modelId}");
        $this->newLine();

        $provider = $this->choice(
            'Select provider',
            ['openai', 'anthropic', 'google', 'deepseek', 'perplexity', 'other'],
            0
        );

        $name = $this->ask('Display name', ucwords(str_replace('-', ' ', $modelId)));
        $description = $this->ask('Description (optional)');
        $version = $this->ask('Version (optional)');

        $capabilities = $this->choice(
            'Select capabilities (comma-separated)',
            ['chat', 'vision', 'function_calling', 'reasoning', 'coding', 'search'],
            0,
            null,
            true
        );

        $inputTokens = $this->ask('Max input tokens', '128000');
        $outputTokens = $this->ask('Max output tokens', '4096');
        
        $inputPrice = $this->ask('Input price per 1M tokens ($)', '0.001');
        $outputPrice = $this->ask('Output price per 1M tokens ($)', '0.003');

        $supportsStreaming = $this->confirm('Supports streaming?', true);
        $supportsVision = in_array('vision', $capabilities);
        $supportsFunctionCalling = in_array('function_calling', $capabilities);

        return [
            'provider' => $provider,
            'model_id' => $modelId,
            'name' => $name,
            'version' => $version,
            'description' => $description,
            'capabilities' => $capabilities,
            'context_window' => [
                'input' => (int) $inputTokens,
                'output' => (int) $outputTokens,
            ],
            'pricing' => [
                'input' => (float) $inputPrice / 1000,
                'output' => (float) $outputPrice / 1000,
            ],
            'max_tokens' => (int) $outputTokens,
            'supports_streaming' => $supportsStreaming,
            'supports_vision' => $supportsVision,
            'supports_function_calling' => $supportsFunctionCalling,
            'is_active' => true,
            'released_at' => now(),
        ];
    }

    protected function collectFromOptions(string $modelId): array
    {
        $provider = $this->option('provider') ?? 'openai';
        $name = $this->option('name') ?? ucwords(str_replace('-', ' ', $modelId));
        $description = $this->option('description');

        return [
            'provider' => $provider,
            'model_id' => $modelId,
            'name' => $name,
            'description' => $description,
            'capabilities' => ['chat'],
            'supports_streaming' => true,
            'is_active' => true,
            'released_at' => now(),
        ];
    }

    protected function displayModelInfo($model): void
    {
        $this->table(
            ['Property', 'Value'],
            [
                ['Model ID', $model->model_id],
                ['Name', $model->name],
                ['Provider', $model->provider],
                ['Version', $model->version ?? 'N/A'],
                ['Capabilities', implode(', ', $model->capabilities ?? [])],
                ['Streaming', $model->supports_streaming ? '✅' : '❌'],
                ['Vision', $model->supports_vision ? '✅' : '❌'],
                ['Function Calling', $model->supports_function_calling ? '✅' : '❌'],
                ['Status', $model->is_active ? '✅ Active' : '❌ Inactive'],
            ]
        );
    }
}
