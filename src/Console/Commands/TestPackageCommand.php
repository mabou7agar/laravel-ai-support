<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\AIEngineManager;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\CacheManager;
use LaravelAIEngine\Services\RateLimitManager;
use LaravelAIEngine\Services\AnalyticsManager;
use LaravelAIEngine\Services\ConversationManager;
use LaravelAIEngine\Services\ActionManager;
use LaravelAIEngine\Services\Failover\FailoverManager;
use LaravelAIEngine\Services\Streaming\WebSocketManager;
use LaravelAIEngine\Services\Analytics\AnalyticsManager as NewAnalyticsManager;
use LaravelAIEngine\Events\AISessionStarted;
use LaravelAIEngine\Events\AIResponseComplete;
use LaravelAIEngine\Events\AIStreamingError;
use LaravelAIEngine\Events\AIActionTriggered;
use LaravelAIEngine\Events\AIFailoverTriggered;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;

class TestPackageCommand extends Command
{
    protected $signature = 'ai-engine:test-package
                            {--skip-events : Skip event system tests}
                            {--skip-services : Skip service tests}
                            {--skip-config : Skip configuration tests}';

    protected $description = 'Comprehensive test suite for Laravel AI Engine package';

    protected int $passedTests = 0;
    protected int $failedTests = 0;
    protected array $failures = [];

    public function handle(): int
    {
        $this->info('🚀 Laravel AI Engine - Comprehensive Package Test Suite');
        $this->newLine();

        $startTime = microtime(true);

        // Run test suites
        if (!$this->option('skip-config')) {
            $this->testConfiguration();
        }

        if (!$this->option('skip-services')) {
            $this->testServices();
        }

        if (!$this->option('skip-events')) {
            $this->testEventSystem();
        }

        $this->testEnums();
        $this->testPublishableAssets();

        // Display results
        $this->displayResults($startTime);

        return $this->failedTests > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function testConfiguration(): void
    {
        $this->section('Configuration Tests');

        // Test config file exists
        $this->test('Config file exists', function () {
            return config('ai-engine') !== null;
        });

        // Test required config keys
        $requiredKeys = ['engines', 'credits', 'cache', 'rate_limiting', 'analytics'];
        foreach ($requiredKeys as $key) {
            $this->test("Config key '{$key}' exists", function () use ($key) {
                return config("ai-engine.{$key}") !== null;
            });
        }

        // Test engine configurations
        $engines = ['openai', 'anthropic', 'gemini', 'stability'];
        foreach ($engines as $engine) {
            $this->test("Engine '{$engine}' configured", function () use ($engine) {
                return config("ai-engine.engines.{$engine}") !== null;
            });
        }
    }

    protected function testServices(): void
    {
        $this->section('Service Container Tests');

        // Test core services
        $services = [
            'AIEngineManager' => AIEngineManager::class,
            'CreditManager' => CreditManager::class,
            'CacheManager' => CacheManager::class,
            'RateLimitManager' => RateLimitManager::class,
            'AnalyticsManager' => AnalyticsManager::class,
            'ConversationManager' => ConversationManager::class,
        ];

        foreach ($services as $name => $class) {
            $this->test("{$name} can be resolved", function () use ($class) {
                $service = app($class);
                return $service instanceof $class;
            });
        }

        // Test enterprise services
        $this->test("ActionManager can be resolved", function () {
            $service = app(ActionManager::class);
            return $service instanceof ActionManager;
        });

        $this->test("NewAnalyticsManager can be resolved", function () {
            $service = app(NewAnalyticsManager::class);
            return $service instanceof NewAnalyticsManager;
        });

        // Test optional enterprise services (may have external dependencies)
        $this->test("FailoverManager class file exists", function () {
            return file_exists(__DIR__ . '/../../Services/Failover/FailoverManager.php');
        });

        $this->test("WebSocketManager class file exists", function () {
            return file_exists(__DIR__ . '/../../Services/Streaming/WebSocketManager.php');
        });

        // Test AIEngineManager methods
        $this->test('AIEngineManager has required methods', function () {
            $manager = app(AIEngineManager::class);
            return method_exists($manager, 'engine') &&
                   method_exists($manager, 'model') &&
                   method_exists($manager, 'getAvailableEngines') &&
                   method_exists($manager, 'getAvailableModels');
        });

        // Test service method calls
        $this->test('AIEngineManager->getAvailableEngines() works', function () {
            $manager = app(AIEngineManager::class);
            $engines = $manager->getAvailableEngines();
            return is_array($engines) && count($engines) > 0;
        });

        $this->test('AIEngineManager->getAvailableModels() works', function () {
            $manager = app(AIEngineManager::class);
            $models = $manager->getAvailableModels();
            return is_array($models) && count($models) > 0;
        });
    }

    protected function testEventSystem(): void
    {
        $this->section('Event System Tests');

        // Test event classes exist
        $events = [
            'AISessionStarted' => AISessionStarted::class,
            'AIResponseComplete' => AIResponseComplete::class,
            'AIStreamingError' => AIStreamingError::class,
            'AIActionTriggered' => AIActionTriggered::class,
            'AIFailoverTriggered' => AIFailoverTriggered::class,
        ];

        foreach ($events as $name => $class) {
            $this->test("Event class '{$name}' exists", function () use ($class) {
                return class_exists($class);
            });
        }

        // Test listener classes exist
        $listeners = [
            'AnalyticsTrackingListener' => \LaravelAIEngine\Listeners\AnalyticsTrackingListener::class,
            'StreamingLoggingListener' => \LaravelAIEngine\Listeners\StreamingLoggingListener::class,
            'StreamingNotificationListener' => \LaravelAIEngine\Listeners\StreamingNotificationListener::class,
        ];

        foreach ($listeners as $name => $class) {
            $this->test("Listener class '{$name}' exists", function () use ($class) {
                return class_exists($class);
            });
        }

        // Test event dispatching
        $this->test('Can dispatch AISessionStarted event', function () {
            try {
                event(new AISessionStarted('test-session', 'user-123', 'openai', 'gpt-4o'));
                return true;
            } catch (\Exception $e) {
                $this->verbose("Error: {$e->getMessage()}");
                return false;
            }
        });

        $this->test('Can dispatch AIResponseComplete event', function () {
            try {
                event(new AIResponseComplete(
                    'req-123',
                    'user-123',
                    'openai',
                    'gpt-4o',
                    'Test content',
                    100,
                    1500.0
                ));
                return true;
            } catch (\Exception $e) {
                $this->verbose("Error: {$e->getMessage()}");
                return false;
            }
        });

        // Test event listeners are registered
        $this->test('Event listeners are registered', function () {
            $listeners = app('events')->getListeners(AISessionStarted::class);
            return count($listeners) > 0;
        });
    }

    protected function testEnums(): void
    {
        $this->section('Enum Tests');

        // Test EngineEnum
        $this->test('EngineEnum has cases', function () {
            return count(EngineEnum::cases()) > 0;
        });

        $this->test('EngineEnum::fromSlug() works', function () {
            try {
                $engine = EngineEnum::fromSlug('openai');
                return $engine === EngineEnum::OPENAI;
            } catch (\Exception $e) {
                return false;
            }
        });

        // Test EntityEnum
        $this->test('EntityEnum has cases', function () {
            return count(EntityEnum::cases()) > 0;
        });

        $this->test('EntityEnum::fromSlug() works', function () {
            try {
                $entity = EntityEnum::fromSlug('gpt-4o');
                return $entity instanceof EntityEnum;
            } catch (\Exception $e) {
                return false;
            }
        });

        // Test enum methods
        $this->test('EngineEnum has required methods', function () {
            $engine = EngineEnum::OPENAI;
            return method_exists($engine, 'label') &&
                   method_exists($engine, 'driverClass') &&
                   method_exists($engine, 'capabilities');
        });
    }

    protected function testPublishableAssets(): void
    {
        $this->section('Publishable Assets Tests');

        // Test config file
        $this->test('Config file exists in package', function () {
            return file_exists(__DIR__ . '/../../../config/ai-engine.php');
        });

        // Test migrations directory
        $this->test('Migrations directory exists', function () {
            return is_dir(__DIR__ . '/../../../database/migrations');
        });

        // Test views directory
        $this->test('Views directory exists', function () {
            return is_dir(__DIR__ . '/../../../resources/views');
        });

        // Test views files
        $this->test('AI Chat component exists', function () {
            return file_exists(__DIR__ . '/../../../resources/views/components/ai-chat.blade.php');
        });

        // Test JS assets
        $this->test('JavaScript assets directory exists', function () {
            return is_dir(__DIR__ . '/../../../resources/js');
        });

        // Test routes
        $this->test('Routes file exists', function () {
            return file_exists(__DIR__ . '/../../../routes/chat.php');
        });
    }

    protected function test(string $description, callable $test): void
    {
        try {
            $result = $test();
            
            if ($result) {
                $this->passedTests++;
                $this->line("  <fg=green>✓</> {$description}");
            } else {
                $this->failedTests++;
                $this->failures[] = $description;
                $this->line("  <fg=red>✗</> {$description}");
            }
        } catch (\Exception $e) {
            $this->failedTests++;
            $this->failures[] = $description;
            $this->line("  <fg=red>✗</> {$description}");
            $this->verbose("    Error: {$e->getMessage()}");
        }
    }

    protected function section(string $title): void
    {
        $this->newLine();
        $this->info("📋 {$title}");
        $this->line(str_repeat('─', 60));
    }

    protected function verbose(string $message): void
    {
        if ($this->getOutput()->isVerbose()) {
            $this->line($message);
        }
    }

    protected function displayResults(float $startTime): void
    {
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        $total = $this->passedTests + $this->failedTests;
        $successRate = $total > 0 ? round(($this->passedTests / $total) * 100, 2) : 0;

        $this->newLine(2);
        $this->line(str_repeat('═', 60));
        $this->info('📊 Test Results Summary');
        $this->line(str_repeat('═', 60));

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Tests', $total],
                ['Passed', "<fg=green>{$this->passedTests}</>"],
                ['Failed', $this->failedTests > 0 ? "<fg=red>{$this->failedTests}</>" : $this->failedTests],
                ['Success Rate', "{$successRate}%"],
                ['Duration', "{$duration}ms"],
            ]
        );

        if ($this->failedTests > 0) {
            $this->newLine();
            $this->error('❌ Failed Tests:');
            foreach ($this->failures as $failure) {
                $this->line("  • {$failure}");
            }
        }

        $this->newLine();
        if ($this->failedTests === 0) {
            $this->info('🎉 All tests passed! Package is working correctly.');
        } else {
            $this->warn("⚠️  {$this->failedTests} test(s) failed. Please review the errors above.");
        }

        $this->newLine();
    }
}
