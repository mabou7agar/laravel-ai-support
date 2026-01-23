<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Facades\AIEngine;
use LaravelAIEngine\Exceptions\AIEngineException;

class TestEnginesCommand extends Command
{
    protected $signature = 'ai-engine:test-engines 
                           {--engine= : Test specific engine only}
                           {--model= : Test specific model only}
                           {--timeout=30 : Request timeout in seconds}
                           {--quick : Run quick tests only}
                           {--export= : Export results to file}';

    protected $description = 'Test all configured AI engines and models';

    public function handle(): int
    {
        $this->info('🚀 Testing AI Engines...');
        $this->newLine();

        $engineFilter = $this->option('engine');
        $modelFilter = $this->option('model');
        $timeout = (int) $this->option('timeout');
        $quick = $this->option('quick');
        $exportPath = $this->option('export');
        $verbose = $this->getOutput()->isVerbose();

        // Validate engine filter
        if ($engineFilter) {
            try {
                $engines = [EngineEnum::from($engineFilter)];
            } catch (\ValueError $e) {
                $this->error("❌ Invalid engine: {$engineFilter}");
                $this->line("Available engines: " . implode(', ', array_map(fn($e) => $e->value, EngineEnum::cases())));
                return self::FAILURE;
            }
        } else {
            $engines = EngineEnum::cases();
        }

        $results = [];
        $totalTests = 0;
        $passedTests = 0;

        foreach ($engines as $engine) {
            if (!$this->isEngineConfigured($engine)) {
                $this->warn("⚠️  {$engine->value} - API key not configured");
                continue;
            }

            $this->info("🔧 Testing {$engine->value}...");

            $models = $this->getModelsForEngine($engine, $modelFilter, $quick);

            foreach ($models as $model) {
                $totalTests++;
                $result = $this->testModel($engine, $model, $timeout, $verbose);
                $results[$engine->value][$model->value] = $result;

                if ($result['success']) {
                    $passedTests++;
                    $this->line("  ✅ {$model->value} - {$result['response_time']}ms");
                } else {
                    $this->line("  ❌ {$model->value} - {$result['error']}");
                }

                if ($verbose && $result['success']) {
                    $this->line("     Content: " . substr($result['content'], 0, 100) . '...');
                    $this->line("     Credits: {$result['credits_used']}");
                }
            }

            $this->newLine();
        }

        // Summary
        $this->info('📊 Test Summary:');
        $this->table(
            ['Engine', 'Models Tested', 'Passed', 'Failed', 'Success Rate'],
            $this->generateSummaryTable($results)
        );

        $overallSuccessRate = $totalTests > 0 ? round(($passedTests / $totalTests) * 100, 1) : 0;

        if ($overallSuccessRate >= 80) {
            $this->info("🎉 Overall Success Rate: {$overallSuccessRate}% ({$passedTests}/{$totalTests})");
        } else {
            $this->error("⚠️  Overall Success Rate: {$overallSuccessRate}% ({$passedTests}/{$totalTests})");
        }

        // Export results if requested
        if ($exportPath) {
            $this->exportResults($results, $exportPath);
            $this->info("📁 Results exported to: {$exportPath}");
        }

        return $overallSuccessRate >= 80 ? self::SUCCESS : self::FAILURE;
    }

    private function isEngineConfigured(EngineEnum $engine): bool
    {
        // Build config key from engine value
        $configKey = "ai-engine.engines.{$engine->value}.api_key";

        $apiKey = config($configKey);

        // Check if API key is a non-empty string
        return is_string($apiKey) && !empty(trim($apiKey));
    }

    private function getModelsForEngine(EngineEnum $engine, ?string $modelFilter, bool $quick = false): array
    {
        $allModels = array_filter(EntityEnum::cases(), fn($model) => $model->engine() === $engine);

        if ($modelFilter) {
            $allModels = array_filter($allModels, fn($model) => $model->value === $modelFilter);
        }

        // Limit models for quick testing
        if ($quick && !$modelFilter && count($allModels) > 1) {
            $allModels = array_slice($allModels, 0, 1);
        } elseif (!$modelFilter && count($allModels) > 3) {
            $allModels = array_slice($allModels, 0, 3);
        }

        return $allModels;
    }

    private function testModel(EngineEnum $engine, EntityEnum $model, int $timeout, bool $verbose): array
    {
        try {
            $startTime = microtime(true);

            $testPrompt = $this->getTestPrompt($model);
            $testParameters = $this->getTestParameters($model);

            $request = new AIRequest(
                prompt: $testPrompt,
                engine: $engine,
                model: $model,
                parameters: $testParameters,
                userId: 'test-user'
            );

            $response = AIEngine::engine($engine->value)
                ->model($model->value)
                ->generate($testPrompt, $testParameters);

            $responseTime = round((microtime(true) - $startTime) * 1000);

            return [
                'success' => true,
                'content' => $response->getContent(),
                'response_time' => $responseTime,
                'credits_used' => $response->usage['total_cost'] ?? 0,
                'metadata' => $response->metadata ?? [],
            ];

        } catch (AIEngineException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'response_time' => null,
                'credits_used' => 0,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Unexpected error: ' . $e->getMessage(),
                'response_time' => null,
                'credits_used' => 0,
            ];
        }
    }

    private function getTestPrompt(EntityEnum $model): string
    {
        return match ($model->getContentType()) {
            'text' => 'Hello, this is a test prompt. Please respond with a brief greeting.',
            'image' => 'A simple red circle on white background',
            'video' => 'A short animation of a bouncing ball',
            'audio' => 'Hello, this is a test of text to speech.',
            'search' => 'Laravel framework',
            'plagiarism' => 'This is a test document for plagiarism checking.',
            'translation' => 'Hello world, how are you today?',
            default => 'Test prompt for AI engine',
        };
    }

    private function getTestParameters(EntityEnum $model): array
    {
        return match ($model->getContentType()) {
            'image' => [
                'image_size' => '512x512',
                'num_images' => 1,
            ],
            'video' => [
                'duration' => 3,
                'fps' => 24,
            ],
            'audio' => [
                'voice' => 'en-US-AriaNeural',
            ],
            'search' => [
                'num_results' => 5,
            ],
            'plagiarism' => [
                'check_type' => 'basic',
            ],
            'translation' => [
                'target_language' => 'es',
            ],
            default => [],
        };
    }

    private function generateSummaryTable(array $results): array
    {
        $table = [];

        foreach ($results as $engine => $models) {
            $total = count($models);
            $passed = count(array_filter($models, fn($result) => $result['success']));
            $failed = $total - $passed;
            $successRate = $total > 0 ? round(($passed / $total) * 100, 1) . '%' : '0%';

            $table[] = [
                $engine,
                $total,
                $passed,
                $failed,
                $successRate,
            ];
        }

        return $table;
    }

    private function exportResults(array $results, string $exportPath): void
    {
        $exportData = [
            'timestamp' => now()->toISOString(),
            'summary' => $this->generateSummaryTable($results),
            'detailed_results' => $results,
        ];

        $directory = dirname($exportPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($exportPath, json_encode($exportData, JSON_PRETTY_PRINT));
    }
}
