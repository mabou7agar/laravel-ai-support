<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Feature\Live;

use Illuminate\Support\Facades\Artisan;
use LaravelAIEngine\Tests\TestCase;

class LiveEndToEndAgentFlowTest extends TestCase
{
    public function test_real_agent_flow_runs_end_to_end_against_live_provider_when_enabled(): void
    {
        if (!$this->readBoolEnv('AI_ENGINE_RUN_LIVE_TESTS')) {
            $this->markTestSkipped('Set AI_ENGINE_RUN_LIVE_TESTS=true to enable live provider end-to-end tests.');
        }

        $engine = (string) (getenv('AI_ENGINE_LIVE_ENGINE') ?: 'openai');
        $model = (string) (getenv('AI_ENGINE_LIVE_MODEL') ?: 'gpt-4o-mini');

        $this->configureLiveProvider($engine, $model);

        $userId = (string) (getenv('AI_ENGINE_LIVE_USER') ?: $this->createTestUser()->id);
        $options = [
            '--session' => 'live-e2e-' . uniqid(),
            '--user' => $userId,
            '--engine' => $engine,
            '--model' => $model,
            '--json' => true,
        ];

        $messages = $this->liveMessages();
        if ($messages !== []) {
            $options['--message'] = $messages;
        } else {
            $options['--script'] = (string) (getenv('AI_ENGINE_LIVE_SCRIPT') ?: 'minimal');
        }

        if ($this->readBoolEnv('AI_ENGINE_LIVE_LOCAL_ONLY', true)) {
            $options['--local-only'] = true;
        }

        $exitCode = Artisan::call('ai:test-real-agent', $options);
        $output = Artisan::output();
        $payload = $this->decodeCommandJson($output);
        $outputDebug = trim($output) !== ''
            ? $output
            : 'No JSON command output captured; raw output length ' . strlen($output) . '.';

        $this->assertSame(0, $exitCode, $output);
        $this->assertIsArray($payload, $outputDebug);
        $this->assertIsArray($payload['summary'] ?? null, $outputDebug);
        $this->assertSame(0, $payload['summary']['failed_turns'] ?? null, $outputDebug);
        $this->assertGreaterThan(0, $payload['summary']['successful_turns'] ?? 0, $outputDebug);
        $this->assertNotEmpty($payload['turns'] ?? [], $outputDebug);
    }

    private function configureLiveProvider(string $engine, string $model): void
    {
        config()->set('ai-engine.default', $engine);
        config()->set('ai-engine.default_model', $model);

        match ($engine) {
            'openai' => $this->setLiveApiKey('ai-engine.engines.openai.api_key', 'OPENAI_API_KEY'),
            'anthropic' => $this->setLiveApiKey('ai-engine.engines.anthropic.api_key', 'ANTHROPIC_API_KEY'),
            'gemini' => $this->setLiveApiKey('ai-engine.engines.gemini.api_key', 'GEMINI_API_KEY'),
            'openrouter' => $this->setLiveApiKey('ai-engine.engines.openrouter.api_key', 'OPENROUTER_API_KEY'),
            default => $this->markTestSkipped("Unsupported live engine [{$engine}] for LiveEndToEndAgentFlowTest."),
        };
    }

    private function setLiveApiKey(string $configKey, string $envKey): void
    {
        $apiKey = getenv($envKey) ?: null;
        if (!is_string($apiKey) || trim($apiKey) === '') {
            $this->markTestSkipped("Missing required live provider credential [{$envKey}].");
        }

        config()->set($configKey, $apiKey);
    }

    private function liveMessages(): array
    {
        $raw = getenv('AI_ENGINE_LIVE_MESSAGES');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $segments = preg_split('/\r?\n|\s*\|\|\s*/', $raw) ?: [];

        return array_values(array_filter(array_map('trim', $segments), static fn (string $message): bool => $message !== ''));
    }

    private function readBoolEnv(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
