<?php

namespace LaravelAIEngine\Tests;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Config;

/**
 * Trait to load API keys from the parent project's .env file
 * so package integration tests can make real API calls.
 *
 * Keys loaded from parent .env take precedence over phpunit.xml placeholders.
 * If the parent .env is not found, tests fall back to placeholder keys.
 */
trait LoadsParentEnv
{
    /** @var array<string, string> Cached parent env values */
    protected static array $parentEnvCache = [];

    /**
     * Load the parent project's .env file and cache its values.
     */
    protected function loadParentEnv(): void
    {
        if (!empty(static::$parentEnvCache)) {
            return;
        }

        // Walk up from package root to find the parent project .env
        $packageRoot = dirname(__DIR__);
        $searchPaths = [
            dirname($packageRoot, 2), // ../../ (packages/laravel-ai-engine -> project root)
            dirname($packageRoot, 1), // ../ (in case of different nesting)
        ];

        foreach ($searchPaths as $path) {
            $envFile = $path . '/.env';
            if (file_exists($envFile)) {
                try {
                    $dotenv = Dotenv::createArrayBacked($path, '.env');
                    static::$parentEnvCache = $dotenv->load();
                } catch (\Exception $e) {
                    // Silently fail — tests will use placeholder keys
                }
                break;
            }
        }
    }

    /**
     * Get a value from the parent .env, falling back to current env() or a default.
     */
    protected function parentEnv(string $key, mixed $default = null): mixed
    {
        return static::$parentEnvCache[$key] ?? env($key) ?? $default;
    }

    /**
     * Wire real API keys from the parent .env into the ai-engine config.
     * Call this in setUpConfig() after setting defaults.
     */
    protected function wireParentEnvKeys(): void
    {
        $this->loadParentEnv();

        $keyMap = [
            // env key => config path
            'OPENAI_API_KEY'            => 'ai-engine.engines.openai.api_key',
            'ANTHROPIC_API_KEY'         => 'ai-engine.engines.anthropic.api_key',
            'GEMINI_API_KEY'            => 'ai-engine.engines.gemini.api_key',
            'DEEPSEEK_API_KEY'          => 'ai-engine.engines.deepseek.api_key',
            'STABLE_DIFFUSION_API_KEY'  => 'ai-engine.engines.stable_diffusion.api_key',
            'ELEVENLABS_API_KEY'        => 'ai-engine.engines.eleven_labs.api_key',
            'FAL_AI_API_KEY'            => 'ai-engine.engines.fal_ai.api_key',
            'PERPLEXITY_API_KEY'        => 'ai-engine.engines.perplexity.api_key',
            'SERPER_API_KEY'            => 'ai-engine.engines.serper.api_key',
            'UNSPLASH_ACCESS_KEY'       => 'ai-engine.engines.unsplash.api_key',
            'PEXELS_API_KEY'            => 'ai-engine.engines.pexels.api_key',
            'PLAGIARISM_CHECK_API_KEY'  => 'ai-engine.engines.plagiarism_check.api_key',
            'MIDJOURNEY_API_KEY'        => 'ai-engine.engines.midjourney.api_key',
            'AZURE_COGNITIVE_KEY'       => 'ai-engine.engines.azure.api_key',
            'AZURE_COGNITIVE_REGION'    => 'ai-engine.engines.azure.region',
            'OPENROUTER_API_KEY'        => 'ai-engine.engines.openrouter.api_key',

            // Non-engine keys
            'QDRANT_HOST'               => 'ai-engine.rag.qdrant.host',
            'QDRANT_API_KEY'            => 'ai-engine.rag.qdrant.api_key',
            'AI_ENGINE_JWT_SECRET'      => 'ai-engine.nodes.jwt.secret',
            'AI_ENGINE_DEBUG'           => 'ai-engine.debug',
            'AI_DEFAULT_ENGINE'         => 'ai-engine.default',
            'AI_DEFAULT_MODEL'          => 'ai-engine.default_model',
            'PLAGIARISM_CHECK_BASE_URL' => 'ai-engine.engines.plagiarism_check.base_url',
        ];

        foreach ($keyMap as $envKey => $configPath) {
            $value = $this->parentEnv($envKey);
            if ($value !== null && $value !== '' && $value !== 'test-key') {
                Config::set($configPath, $value);
            }
        }
    }

    /**
     * Check if a real (non-placeholder) API key is available for an engine.
     */
    protected function hasRealApiKey(string $engine): bool
    {
        $key = config("ai-engine.engines.{$engine}.api_key");
        return $key && $key !== 'test-key' && !str_starts_with($key, 'test-');
    }
}
