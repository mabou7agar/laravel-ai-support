<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\Drivers\StableDiffusion\StableDiffusionEngineDriver;
use LaravelAIEngine\Drivers\ElevenLabs\ElevenLabsEngineDriver;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;

/**
 * Engine enumeration class (PHP 8.0 compatible)
 * Replaces native enum for Laravel 9 compatibility
 */
class EngineEnum
{
    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    public const GEMINI = 'gemini';
    public const STABLE_DIFFUSION = 'stable_diffusion';
    public const ELEVEN_LABS = 'eleven_labs';
    public const FAL_AI = 'fal_ai';
    public const DEEPSEEK = 'deepseek';
    public const PERPLEXITY = 'perplexity';
    public const MIDJOURNEY = 'midjourney';
    public const AZURE = 'azure';
    public const GOOGLE_TTS = 'google_tts';
    public const SERPER = 'serper';
    public const PLAGIARISM_CHECK = 'plagiarism_check';
    public const UNSPLASH = 'unsplash';
    public const PEXELS = 'pexels';
    public const OPENROUTER = 'openrouter';

    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Get the driver class for this engine
     */
    public function driverClass(): string
    {
        switch ($this->value) {
            case self::OPENAI:
                return OpenAIEngineDriver::class;
            case self::ANTHROPIC:
                return AnthropicEngineDriver::class;
            case self::GEMINI:
                return GeminiEngineDriver::class;
            case self::STABLE_DIFFUSION:
                return StableDiffusionEngineDriver::class;
            case self::ELEVEN_LABS:
                return ElevenLabsEngineDriver::class;
            case self::FAL_AI:
                return FalAIEngineDriver::class;
            case self::DEEPSEEK:
            case self::PERPLEXITY:
            case self::AZURE:
            case self::SERPER:
            case self::PLAGIARISM_CHECK:
            case self::UNSPLASH:
            case self::PEXELS:
                return OpenAIEngineDriver::class;
            case self::MIDJOURNEY:
                return FalAIEngineDriver::class;
            case self::GOOGLE_TTS:
                return GeminiEngineDriver::class;
            case self::OPENROUTER:
                return OpenRouterEngineDriver::class;
            default:
                throw new \InvalidArgumentException("Unknown engine: {$this->value}");
        }
    }

    /**
     * Get the display label for this engine
     */
    public function label(): string
    {
        return match ($this) {
            self::OPENAI => 'OpenAI',
            self::ANTHROPIC => 'Anthropic',
            self::GEMINI => 'Google Gemini',
            self::STABLE_DIFFUSION => 'Stability AI',
            self::ELEVEN_LABS => 'ElevenLabs',
            self::FAL_AI => 'FAL AI',
            self::DEEPSEEK => 'DeepSeek',
            self::PERPLEXITY => 'Perplexity',
            self::MIDJOURNEY => 'Midjourney',
            self::AZURE => 'Azure OpenAI',
            self::GOOGLE_TTS => 'Google Text-to-Speech',
            self::SERPER => 'Serper Search',
            self::PLAGIARISM_CHECK => 'Plagiarism Check',
            self::UNSPLASH => 'Unsplash',
            self::PEXELS => 'Pexels',
            self::OPENROUTER => 'OpenRouter',
        };
    }

    /**
     * Get the slug for this engine
     */
    public function slug(): string
    {
        return $this->value;
    }

    /**
     * Get supported capabilities for this engine
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::OPENAI => ['text', 'chat', 'images', 'audio', 'embeddings', 'vision'],
            self::ANTHROPIC => ['text', 'chat', 'vision'],
            self::GEMINI => ['text', 'chat', 'vision', 'embeddings'],
            self::STABLE_DIFFUSION => ['images', 'video'],
            self::ELEVEN_LABS => ['audio', 'speech'],
            self::FAL_AI => ['images', 'video', 'audio'],
            self::DEEPSEEK => ['text', 'chat'],
            self::PERPLEXITY => ['text', 'search'],
            self::MIDJOURNEY => ['images'],
            self::AZURE => ['text', 'chat', 'images', 'audio', 'embeddings'],
            self::GOOGLE_TTS => ['audio', 'speech'],
            self::SERPER => ['search'],
            self::PLAGIARISM_CHECK => ['plagiarism'],
            self::UNSPLASH => ['images', 'search'],
            self::PEXELS => ['images', 'search'],
            self::OPENROUTER => ['text', 'chat', 'images', 'vision', 'embeddings'],
        };
    }

    /**
     * Check if engine supports a specific capability
     */
    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities());
    }

    /**
     * Get default models for this engine
     */
    public function getDefaultModels(): array
    {
        return match ($this) {
            self::OPENAI => [
                EntityEnum::GPT_4O,
                EntityEnum::GPT_4O_MINI,
                EntityEnum::DALL_E_3,
                EntityEnum::WHISPER_1,
            ],
            self::ANTHROPIC => [
                EntityEnum::CLAUDE_3_5_SONNET,
                EntityEnum::CLAUDE_3_HAIKU,
            ],
            self::GEMINI => [
                EntityEnum::GEMINI_1_5_PRO,
                EntityEnum::GEMINI_1_5_FLASH,
            ],
            self::STABLE_DIFFUSION => [
                EntityEnum::SD3_LARGE,
                EntityEnum::SDXL_1024,
            ],
            self::ELEVEN_LABS => [
                EntityEnum::ELEVEN_MULTILINGUAL_V2,
            ],
            self::FAL_AI => [
                EntityEnum::FLUX_PRO,
                EntityEnum::KLING_VIDEO,
            ],
            self::DEEPSEEK => [
                EntityEnum::DEEPSEEK_CHAT,
                EntityEnum::DEEPSEEK_REASONER,
            ],
            self::PERPLEXITY => [
                EntityEnum::PERPLEXITY_SONAR_LARGE,
            ],
            self::MIDJOURNEY => [
                EntityEnum::FLUX_PRO, // Using FAL AI for Midjourney-style generation
            ],
            self::AZURE => [
                EntityEnum::GPT_4O,
                EntityEnum::GPT_4O_MINI,
            ],
            self::GOOGLE_TTS => [
                EntityEnum::GEMINI_1_5_PRO,
            ],
            self::SERPER => [
                EntityEnum::SERPER_SEARCH,
            ],
            self::PLAGIARISM_CHECK => [
                EntityEnum::GPT_4O, // Using GPT for plagiarism analysis
            ],
            self::UNSPLASH => [
                EntityEnum::UNSPLASH_SEARCH,
            ],
            self::PEXELS => [
                EntityEnum::UNSPLASH_SEARCH, // Using similar search functionality
            ],
            self::OPENROUTER => [
                EntityEnum::OPENROUTER_GPT_5,
                EntityEnum::OPENROUTER_GEMINI_2_5_PRO,
                EntityEnum::OPENROUTER_CLAUDE_4_OPUS,
                EntityEnum::OPENROUTER_CLAUDE_4_SONNET,
                EntityEnum::OPENROUTER_GPT_5_MINI,
                EntityEnum::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
                EntityEnum::OPENROUTER_LLAMA_3_3_70B,
            ],
        };
    }

    /**
     * Get all available engines
     */
    public static function all(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Create engine from slug
     */
    public static function fromSlug(string $slug): self
    {
        return self::from($slug);
    }
}
