<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\Anthropic\AnthropicEngineDriver;
use LaravelAIEngine\Drivers\Azure\AzureEngineDriver;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Gemini\GeminiEngineDriver;
use LaravelAIEngine\Drivers\GoogleTTS\GoogleTTSEngineDriver;
use LaravelAIEngine\Drivers\StableDiffusion\StableDiffusionEngineDriver;
use LaravelAIEngine\Drivers\ElevenLabs\ElevenLabsEngineDriver;
use LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver;
use LaravelAIEngine\Drivers\Midjourney\MidjourneyEngineDriver;
use LaravelAIEngine\Drivers\NvidiaNim\NvidiaNimEngineDriver;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\Drivers\Ollama\OllamaEngineDriver;
use LaravelAIEngine\Drivers\Perplexity\PerplexityEngineDriver;
use LaravelAIEngine\Drivers\PlagiarismCheck\PlagiarismCheckEngineDriver;
use LaravelAIEngine\Drivers\Serper\SerperEngineDriver;
use LaravelAIEngine\Drivers\CloudflareWorkersAI\CloudflareWorkersAIEngineDriver;
use LaravelAIEngine\Drivers\ComfyUI\ComfyUIEngineDriver;
use LaravelAIEngine\Drivers\HuggingFace\HuggingFaceEngineDriver;
use LaravelAIEngine\Drivers\Replicate\ReplicateEngineDriver;
use LaravelAIEngine\Drivers\Unsplash\UnsplashEngineDriver;
use LaravelAIEngine\Drivers\Pexels\PexelsEngineDriver;

/**
 * Engine enumeration for built-in providers.
 */
enum EngineEnum: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Gemini = 'gemini';
    case StableDiffusion = 'stable_diffusion';
    case ElevenLabs = 'eleven_labs';
    case FalAI = 'fal_ai';
    case DeepSeek = 'deepseek';
    case Perplexity = 'perplexity';
    case Midjourney = 'midjourney';
    case Azure = 'azure';
    case GoogleTts = 'google_tts';
    case Serper = 'serper';
    case PlagiarismCheck = 'plagiarism_check';
    case Unsplash = 'unsplash';
    case Pexels = 'pexels';
    case OpenRouter = 'openrouter';
    case Ollama = 'ollama';
    case NvidiaNim = 'nvidia_nim';
    case CloudflareWorkersAI = 'cloudflare_workers_ai';
    case HuggingFace = 'huggingface';
    case Replicate = 'replicate';
    case ComfyUI = 'comfyui';

    public const OPENAI = 'openai';
    public const ANTHROPIC = 'anthropic';
    public const GEMINI = 'gemini';
    public const STABLE_DIFFUSION = 'stable_diffusion';
    public const ELEVENLABS = 'eleven_labs';
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
    public const NVIDIA_NIM = 'nvidia_nim';
    public const CLOUDFLARE_WORKERS_AI = 'cloudflare_workers_ai';
    public const HUGGINGFACE = 'huggingface';
    public const REPLICATE = 'replicate';
    public const COMFYUI = 'comfyui';

    /**
     * Get the driver class for this engine
     */
    public function driverClass(): string
    {
        return match ($this) {
            self::Pexels             => PexelsEngineDriver::class,
            self::OpenAI             => OpenAIEngineDriver::class,
            self::Unsplash           => UnsplashEngineDriver::class,
            self::PlagiarismCheck    => PlagiarismCheckEngineDriver::class,
            self::Serper             => SerperEngineDriver::class,
            self::Azure              => AzureEngineDriver::class,
            self::Perplexity         => PerplexityEngineDriver::class,
            self::DeepSeek           => DeepSeekEngineDriver::class,
            self::Anthropic          => AnthropicEngineDriver::class,
            self::GoogleTts          => GoogleTTSEngineDriver::class,
            self::Gemini             => GeminiEngineDriver::class,
            self::StableDiffusion    => StableDiffusionEngineDriver::class,
            self::ElevenLabs         => ElevenLabsEngineDriver::class,
            self::FalAI              => FalAIEngineDriver::class,
            self::Midjourney         => MidjourneyEngineDriver::class,
            self::OpenRouter         => OpenRouterEngineDriver::class,
            self::Ollama             => OllamaEngineDriver::class,
            self::NvidiaNim          => NvidiaNimEngineDriver::class,
            self::CloudflareWorkersAI => CloudflareWorkersAIEngineDriver::class,
            self::HuggingFace        => HuggingFaceEngineDriver::class,
            self::Replicate          => ReplicateEngineDriver::class,
            self::ComfyUI            => ComfyUIEngineDriver::class,
        };
    }

    /**
     * Get the display label for this engine
     */
    public function label(): string
    {
        return match ($this) {
            self::OpenAI             => 'OpenAI',
            self::Anthropic          => 'Anthropic',
            self::Gemini             => 'Google Gemini',
            self::StableDiffusion    => 'Stability AI',
            self::ElevenLabs         => 'ElevenLabs',
            self::FalAI              => 'FAL AI',
            self::DeepSeek           => 'DeepSeek',
            self::Perplexity         => 'Perplexity',
            self::Midjourney         => 'Midjourney',
            self::Azure              => 'Azure OpenAI',
            self::GoogleTts          => 'Google Text-to-Speech',
            self::Serper             => 'Serper Search',
            self::PlagiarismCheck    => 'Plagiarism Check',
            self::Unsplash           => 'Unsplash',
            self::Pexels             => 'Pexels',
            self::OpenRouter         => 'OpenRouter',
            self::Ollama             => 'Ollama (Local)',
            self::NvidiaNim          => 'NVIDIA NIM',
            self::CloudflareWorkersAI => 'Cloudflare Workers AI',
            self::HuggingFace        => 'Hugging Face',
            self::Replicate          => 'Replicate',
            self::ComfyUI            => 'ComfyUI',
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
            self::OpenAI             => ['text', 'chat', 'images', 'audio', 'embeddings', 'vision', 'speech_to_text', 'text_to_speech', 'tts'],
            self::Anthropic          => ['text', 'chat', 'vision'],
            self::Gemini             => ['text', 'chat', 'vision', 'embeddings'],
            self::StableDiffusion    => ['images', 'video'],
            self::ElevenLabs         => ['audio', 'speech'],
            self::FalAI              => ['images', 'video', 'audio'],
            self::DeepSeek           => ['text', 'chat'],
            self::Perplexity         => ['text', 'search'],
            self::Midjourney         => ['images'],
            self::Azure              => ['text', 'chat', 'images', 'audio', 'embeddings'],
            self::GoogleTts          => ['audio', 'speech', 'text_to_speech', 'tts'],
            self::Serper             => ['search'],
            self::PlagiarismCheck    => ['plagiarism'],
            self::Unsplash           => ['images', 'search'],
            self::Pexels             => ['images', 'search'],
            self::OpenRouter         => ['text', 'chat', 'images', 'vision', 'embeddings'],
            self::Ollama             => ['text', 'chat', 'embeddings'],
            self::NvidiaNim          => ['text', 'chat', 'streaming'],
            self::CloudflareWorkersAI => ['images', 'audio', 'speech_to_text', 'text_to_speech'],
            self::HuggingFace        => ['images', 'video', 'audio', 'speech_to_text', 'text_to_speech'],
            self::Replicate          => ['images', 'video', 'audio'],
            self::ComfyUI            => ['images', 'video', 'audio'],
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
     * Get default models for this engine (returns EntityEnum instances)
     */
    public function getDefaultModels(): array
    {
        $ids = match ($this) {
            self::OpenAI => [
                EntityEnum::GPT_4O,
                EntityEnum::GPT_4O_MINI,
                EntityEnum::GPT_IMAGE_1_MINI,
                EntityEnum::DALL_E_3,
                EntityEnum::WHISPER_1,
                EntityEnum::OPENAI_GPT_4O_MINI_TTS,
                EntityEnum::OPENAI_TTS_1,
                EntityEnum::OPENAI_TTS_1_HD,
            ],
            self::Anthropic => [
                EntityEnum::CLAUDE_3_5_SONNET,
                EntityEnum::CLAUDE_3_HAIKU,
            ],
            self::Gemini => [
                EntityEnum::GEMINI_1_5_PRO,
                EntityEnum::GEMINI_1_5_FLASH,
                EntityEnum::GEMINI_2_5_FLASH_TTS,
            ],
            self::StableDiffusion => [
                EntityEnum::SD3_LARGE,
                EntityEnum::SDXL_1024,
            ],
            self::ElevenLabs => [
                EntityEnum::ELEVEN_MULTILINGUAL_V2,
            ],
            self::FalAI => [
                EntityEnum::FLUX_PRO,
                EntityEnum::KLING_VIDEO,
            ],
            self::DeepSeek => [
                EntityEnum::DEEPSEEK_CHAT,
                EntityEnum::DEEPSEEK_REASONER,
            ],
            self::Perplexity => [
                EntityEnum::PERPLEXITY_SONAR_LARGE,
            ],
            self::Midjourney => [
                EntityEnum::FLUX_PRO,
            ],
            self::Azure => [
                EntityEnum::GPT_4O,
                EntityEnum::GPT_4O_MINI,
            ],
            self::GoogleTts => [
                EntityEnum::GOOGLE_TTS,
            ],
            self::Serper => [
                EntityEnum::SERPER_SEARCH,
            ],
            self::PlagiarismCheck => [
                EntityEnum::GPT_4O,
            ],
            self::Unsplash => [
                EntityEnum::UNSPLASH_SEARCH,
            ],
            self::Pexels => [
                EntityEnum::PEXELS_SEARCH,
            ],
            self::OpenRouter => [
                EntityEnum::OPENROUTER_GPT_5,
                EntityEnum::OPENROUTER_GEMINI_2_5_PRO,
                EntityEnum::OPENROUTER_CLAUDE_4_OPUS,
                EntityEnum::OPENROUTER_CLAUDE_4_SONNET,
                EntityEnum::OPENROUTER_GPT_5_MINI,
                EntityEnum::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
                EntityEnum::OPENROUTER_LLAMA_3_3_70B,
            ],
            self::Ollama => [
                EntityEnum::OLLAMA_LLAMA2,
                EntityEnum::OLLAMA_LLAMA3,
                EntityEnum::OLLAMA_MISTRAL,
                EntityEnum::OLLAMA_CODELLAMA,
            ],
            self::NvidiaNim => [
                EntityEnum::NVIDIA_NIM_NEMOTRON_70B,
                EntityEnum::NVIDIA_NIM_LLAMA_3_1_70B,
                EntityEnum::NVIDIA_NIM_LLAMA_3_1_8B,
            ],
            default => [],
        };

        return array_map(static fn(string $id) => EntityEnum::from($id), $ids);
    }

    /**
     * Get all available engine value strings
     */
    public static function all(): array
    {
        return array_map(static fn(self $engine): string => $engine->value, self::cases());
    }

    /**
     * Create engine from slug (alias for from)
     */
    public static function fromSlug(string $slug): self
    {
        return self::from($slug);
    }
}
