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
use LaravelAIEngine\Drivers\Ollama\OllamaEngineDriver;

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
    public const OLLAMA = 'ollama';

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
            case self::PEXELS:
            case self::UNSPLASH:
            case self::PLAGIARISM_CHECK:
            case self::SERPER:
            case self::AZURE:
            case self::PERPLEXITY:
            case self::DEEPSEEK:
            case self::OPENAI:
                return OpenAIEngineDriver::class;
            case self::ANTHROPIC:
                return AnthropicEngineDriver::class;
            case self::GOOGLE_TTS:
            case self::GEMINI:
                return GeminiEngineDriver::class;
            case self::STABLE_DIFFUSION:
                return StableDiffusionEngineDriver::class;
            case self::ELEVEN_LABS:
                return ElevenLabsEngineDriver::class;
            case self::MIDJOURNEY:
            case self::FAL_AI:
                return FalAIEngineDriver::class;
            case self::OPENROUTER:
                return OpenRouterEngineDriver::class;
            case self::OLLAMA:
                return OllamaEngineDriver::class;
            default:
                throw new \InvalidArgumentException("Unknown engine: {$this->value}");
        }
    }

    /**
     * Get the display label for this engine
     */
    public function label(): string
    {
        switch ($this->value) {
            case self::OPENAI:
                return 'OpenAI';
            case self::ANTHROPIC:
                return 'Anthropic';
            case self::GEMINI:
                return 'Google Gemini';
            case self::STABLE_DIFFUSION:
                return 'Stability AI';
            case self::ELEVEN_LABS:
                return 'ElevenLabs';
            case self::FAL_AI:
                return 'FAL AI';
            case self::DEEPSEEK:
                return 'DeepSeek';
            case self::PERPLEXITY:
                return 'Perplexity';
            case self::MIDJOURNEY:
                return 'Midjourney';
            case self::AZURE:
                return 'Azure OpenAI';
            case self::GOOGLE_TTS:
                return 'Google Text-to-Speech';
            case self::SERPER:
                return 'Serper Search';
            case self::PLAGIARISM_CHECK:
                return 'Plagiarism Check';
            case self::UNSPLASH:
                return 'Unsplash';
            case self::PEXELS:
                return 'Pexels';
            case self::OPENROUTER:
                return 'OpenRouter';
            case self::OLLAMA:
                return 'Ollama (Local)';
            default:
                return ucfirst(str_replace('_', ' ', $this->value));
        }
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
        switch ($this->value) {
            case self::OPENAI:
                return ['text', 'chat', 'images', 'audio', 'embeddings', 'vision'];
            case self::ANTHROPIC:
                return ['text', 'chat', 'vision'];
            case self::GEMINI:
                return ['text', 'chat', 'vision', 'embeddings'];
            case self::STABLE_DIFFUSION:
                return ['images', 'video'];
            case self::ELEVEN_LABS:
                return ['audio', 'speech'];
            case self::FAL_AI:
                return ['images', 'video', 'audio'];
            case self::DEEPSEEK:
                return ['text', 'chat'];
            case self::PERPLEXITY:
                return ['text', 'search'];
            case self::MIDJOURNEY:
                return ['images'];
            case self::AZURE:
                return ['text', 'chat', 'images', 'audio', 'embeddings'];
            case self::GOOGLE_TTS:
                return ['audio', 'speech'];
            case self::SERPER:
                return ['search'];
            case self::PLAGIARISM_CHECK:
                return ['plagiarism'];
            case self::UNSPLASH:
                return ['images', 'search'];
            case self::PEXELS:
                return ['images', 'search'];
            case self::OPENROUTER:
                return ['text', 'chat', 'images', 'vision', 'embeddings'];
            case self::OLLAMA:
                return ['text', 'chat', 'embeddings'];
            default:
                return [];
        }
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
        switch ($this->value) {
            case self::OPENAI:
                return [
                    EntityEnum::GPT_4O,
                    EntityEnum::GPT_4O_MINI,
                    EntityEnum::DALL_E_3,
                    EntityEnum::WHISPER_1,
                ];
            case self::ANTHROPIC:
                return [
                    EntityEnum::CLAUDE_3_5_SONNET,
                    EntityEnum::CLAUDE_3_HAIKU,
                ];
            case self::GEMINI:
                return [
                    EntityEnum::GEMINI_1_5_PRO,
                    EntityEnum::GEMINI_1_5_FLASH,
                ];
            case self::STABLE_DIFFUSION:
                return [
                    EntityEnum::SD3_LARGE,
                    EntityEnum::SDXL_1024,
                ];
            case self::ELEVEN_LABS:
                return [
                    EntityEnum::ELEVEN_MULTILINGUAL_V2,
                ];
            case self::FAL_AI:
                return [
                    EntityEnum::FLUX_PRO,
                    EntityEnum::KLING_VIDEO,
                ];
            case self::DEEPSEEK:
                return [
                    EntityEnum::DEEPSEEK_CHAT,
                    EntityEnum::DEEPSEEK_REASONER,
                ];
            case self::PERPLEXITY:
                return [
                    EntityEnum::PERPLEXITY_SONAR_LARGE,
                ];
            case self::MIDJOURNEY:
                return [
                    EntityEnum::FLUX_PRO,
                ];
            case self::AZURE:
                return [
                    EntityEnum::GPT_4O,
                    EntityEnum::GPT_4O_MINI,
                ];
            case self::GOOGLE_TTS:
                return [
                    EntityEnum::GEMINI_1_5_PRO,
                ];
            case self::SERPER:
                return [
                    EntityEnum::SERPER_SEARCH,
                ];
            case self::PLAGIARISM_CHECK:
                return [
                    EntityEnum::GPT_4O,
                ];
            case self::UNSPLASH:
                return [
                    EntityEnum::UNSPLASH_SEARCH,
                ];
            case self::PEXELS:
                return [
                    EntityEnum::UNSPLASH_SEARCH,
                ];
            case self::OPENROUTER:
                return [
                    EntityEnum::OPENROUTER_GPT_5,
                    EntityEnum::OPENROUTER_GEMINI_2_5_PRO,
                    EntityEnum::OPENROUTER_CLAUDE_4_OPUS,
                    EntityEnum::OPENROUTER_CLAUDE_4_SONNET,
                    EntityEnum::OPENROUTER_GPT_5_MINI,
                    EntityEnum::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
                    EntityEnum::OPENROUTER_LLAMA_3_3_70B,
                ];
            case self::OLLAMA:
                return [
                    EntityEnum::OLLAMA_LLAMA2,
                    EntityEnum::OLLAMA_LLAMA3,
                    EntityEnum::OLLAMA_MISTRAL,
                    EntityEnum::OLLAMA_CODELLAMA,
                ];
            default:
                return [];
        }
    }

    /**
     * Get all available engines
     */
    public static function all(): array
    {
        return [
            self::OPENAI,
            self::ANTHROPIC,
            self::GEMINI,
            self::STABLE_DIFFUSION,
            self::ELEVEN_LABS,
            self::FAL_AI,
            self::DEEPSEEK,
            self::PERPLEXITY,
            self::MIDJOURNEY,
            self::AZURE,
            self::GOOGLE_TTS,
            self::SERPER,
            self::PLAGIARISM_CHECK,
            self::UNSPLASH,
            self::PEXELS,
            self::OPENROUTER,
            self::OLLAMA,
        ];
    }

    /**
     * Get all available engine instances
     */
    public static function cases(): array
    {
        return array_map(fn($value) => new self($value), self::all());
    }

    /**
     * Create engine from value
     */
    public static function from(string $value): self
    {
        if (!in_array($value, self::all())) {
            throw new \InvalidArgumentException("Invalid engine value: {$value}");
        }
        return new self($value);
    }

    /**
     * Try to create engine from value, returns null if invalid
     */
    public static function tryFrom(string $value): ?self
    {
        if (!in_array($value, self::all())) {
            return null;
        }
        return new self($value);
    }

    /**
     * Create engine from slug (alias for from)
     */
    public static function fromSlug(string $slug): self
    {
        return self::from($slug);
    }
}
