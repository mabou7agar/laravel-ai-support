<?php

namespace LaravelAIEngine\Database\Seeders;

use Illuminate\Database\Seeder;
use LaravelAIEngine\Models\AIModel;

class AIModelsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $models = [
            // OpenAI Models
            [
                'provider' => 'openai',
                'model_id' => 'gpt-4o',
                'name' => 'GPT-4o',
                'version' => '2024-11-20',
                'description' => 'Most advanced multimodal model with vision and function calling',
                'capabilities' => ['chat', 'vision', 'function_calling', 'json_mode'],
                'context_window' => ['input' => 128000, 'output' => 4096],
                'pricing' => ['input' => 0.0025, 'output' => 0.01],
                'max_tokens' => 4096,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => true,
                'is_active' => true,
                'released_at' => '2024-11-20',
            ],
            [
                'provider' => 'openai',
                'model_id' => 'gpt-4o-mini',
                'name' => 'GPT-4o Mini',
                'version' => '2024-07-18',
                'description' => 'Affordable and intelligent small model for fast tasks',
                'capabilities' => ['chat', 'vision', 'function_calling', 'json_mode'],
                'context_window' => ['input' => 128000, 'output' => 16384],
                'pricing' => ['input' => 0.00015, 'output' => 0.0006],
                'max_tokens' => 16384,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => true,
                'is_active' => true,
                'released_at' => '2024-07-18',
            ],
            [
                'provider' => 'openai',
                'model_id' => 'gpt-4-turbo',
                'name' => 'GPT-4 Turbo',
                'version' => '2024-04-09',
                'description' => 'Previous generation flagship model',
                'capabilities' => ['chat', 'vision', 'function_calling', 'json_mode'],
                'context_window' => ['input' => 128000, 'output' => 4096],
                'pricing' => ['input' => 0.01, 'output' => 0.03],
                'max_tokens' => 4096,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => true,
                'is_active' => true,
                'released_at' => '2024-04-09',
            ],
            [
                'provider' => 'openai',
                'model_id' => 'o1-preview',
                'name' => 'O1 Preview',
                'version' => '2024-09-12',
                'description' => 'Reasoning model for complex tasks',
                'capabilities' => ['chat', 'reasoning'],
                'context_window' => ['input' => 128000, 'output' => 32768],
                'pricing' => ['input' => 0.015, 'output' => 0.06],
                'max_tokens' => 32768,
                'supports_streaming' => false,
                'supports_vision' => false,
                'supports_function_calling' => false,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-09-12',
            ],
            [
                'provider' => 'openai',
                'model_id' => 'o1-mini',
                'name' => 'O1 Mini',
                'version' => '2024-09-12',
                'description' => 'Faster reasoning model for coding and STEM',
                'capabilities' => ['chat', 'reasoning', 'coding'],
                'context_window' => ['input' => 128000, 'output' => 65536],
                'pricing' => ['input' => 0.003, 'output' => 0.012],
                'max_tokens' => 65536,
                'supports_streaming' => false,
                'supports_vision' => false,
                'supports_function_calling' => false,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-09-12',
            ],

            // Anthropic Models
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-5-sonnet-20241022',
                'name' => 'Claude 3.5 Sonnet',
                'version' => '2024-10-22',
                'description' => 'Most intelligent model with vision and coding',
                'capabilities' => ['chat', 'vision', 'coding', 'analysis'],
                'context_window' => ['input' => 200000, 'output' => 8192],
                'pricing' => ['input' => 0.003, 'output' => 0.015],
                'max_tokens' => 8192,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-10-22',
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-5-haiku-20241022',
                'name' => 'Claude 3.5 Haiku',
                'version' => '2024-10-22',
                'description' => 'Fastest model for quick tasks',
                'capabilities' => ['chat', 'vision'],
                'context_window' => ['input' => 200000, 'output' => 8192],
                'pricing' => ['input' => 0.001, 'output' => 0.005],
                'max_tokens' => 8192,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => false,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-10-22',
            ],
            [
                'provider' => 'anthropic',
                'model_id' => 'claude-3-opus-20240229',
                'name' => 'Claude 3 Opus',
                'version' => '2024-02-29',
                'description' => 'Most powerful model for complex tasks',
                'capabilities' => ['chat', 'vision', 'analysis'],
                'context_window' => ['input' => 200000, 'output' => 4096],
                'pricing' => ['input' => 0.015, 'output' => 0.075],
                'max_tokens' => 4096,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-02-29',
            ],

            // Google Models
            [
                'provider' => 'google',
                'model_id' => 'gemini-1.5-pro',
                'name' => 'Gemini 1.5 Pro',
                'version' => '2024-11',
                'description' => 'Advanced multimodal model with 2M context',
                'capabilities' => ['chat', 'vision', 'audio', 'video', 'function_calling'],
                'context_window' => ['input' => 2097152, 'output' => 8192],
                'pricing' => ['input' => 0.00125, 'output' => 0.005],
                'max_tokens' => 8192,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => true,
                'is_active' => true,
                'released_at' => '2024-11-01',
            ],
            [
                'provider' => 'google',
                'model_id' => 'gemini-1.5-flash',
                'name' => 'Gemini 1.5 Flash',
                'version' => '2024-11',
                'description' => 'Fast and versatile multimodal model',
                'capabilities' => ['chat', 'vision', 'audio', 'function_calling'],
                'context_window' => ['input' => 1048576, 'output' => 8192],
                'pricing' => ['input' => 0.000075, 'output' => 0.0003],
                'max_tokens' => 8192,
                'supports_streaming' => true,
                'supports_vision' => true,
                'supports_function_calling' => true,
                'supports_json_mode' => true,
                'is_active' => true,
                'released_at' => '2024-11-01',
            ],

            // DeepSeek Models
            [
                'provider' => 'deepseek',
                'model_id' => 'deepseek-chat',
                'name' => 'DeepSeek Chat',
                'version' => 'v2.5',
                'description' => 'Advanced chat model with competitive pricing',
                'capabilities' => ['chat', 'function_calling'],
                'context_window' => ['input' => 64000, 'output' => 4096],
                'pricing' => ['input' => 0.00014, 'output' => 0.00028],
                'max_tokens' => 4096,
                'supports_streaming' => true,
                'supports_vision' => false,
                'supports_function_calling' => true,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-09-01',
            ],

            // Perplexity Models
            [
                'provider' => 'perplexity',
                'model_id' => 'llama-3.1-sonar-large-128k-online',
                'name' => 'Sonar Large Online',
                'version' => '3.1',
                'description' => 'Online search-augmented model',
                'capabilities' => ['chat', 'search', 'citations'],
                'context_window' => ['input' => 127072, 'output' => 4096],
                'pricing' => ['input' => 0.001, 'output' => 0.001],
                'max_tokens' => 4096,
                'supports_streaming' => true,
                'supports_vision' => false,
                'supports_function_calling' => false,
                'supports_json_mode' => false,
                'is_active' => true,
                'released_at' => '2024-08-01',
            ],
        ];

        foreach ($models as $modelData) {
            AIModel::updateOrCreate(
                ['model_id' => $modelData['model_id']],
                $modelData
            );
        }

        $this->command->info('✅ AI Models seeded successfully!');
        $this->command->info('📊 Total models: ' . count($models));
    }
}
