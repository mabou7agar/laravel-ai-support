<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

use LaravelAIEngine\Drivers\OpenAI\GPT4ODriver;
use LaravelAIEngine\Drivers\OpenAI\GPT4OMiniDriver;
use LaravelAIEngine\Drivers\OpenAI\GPT35TurboDriver;
use LaravelAIEngine\Drivers\OpenAI\DallE3Driver;
use LaravelAIEngine\Drivers\OpenAI\DallE2Driver;
use LaravelAIEngine\Drivers\OpenAI\WhisperDriver;
use LaravelAIEngine\Drivers\Anthropic\Claude35SonnetDriver;
use LaravelAIEngine\Drivers\Anthropic\Claude3HaikuDriver;
use LaravelAIEngine\Drivers\Anthropic\Claude3OpusDriver;
use LaravelAIEngine\Drivers\Gemini\Gemini15ProDriver;
use LaravelAIEngine\Drivers\Gemini\Gemini15FlashDriver;
use LaravelAIEngine\Drivers\StableDiffusion\SD3LargeDriver;
use LaravelAIEngine\Drivers\StableDiffusion\SDXL1024Driver;
use LaravelAIEngine\Drivers\ElevenLabs\MultilingualV2Driver;
use LaravelAIEngine\Drivers\FalAI\FluxProDriver;
use LaravelAIEngine\Drivers\FalAI\KlingVideoDriver;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekChatDriver;
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekReasonerDriver;

enum EntityEnum: string
{
    // OpenAI Models
    case GPT_4O = 'gpt-4o';
    case GPT_4O_MINI = 'gpt-4o-mini';
    case GPT_3_5_TURBO = 'gpt-3.5-turbo';
    case DALL_E_3 = 'dall-e-3';
    case DALL_E_2 = 'dall-e-2';
    case WHISPER_1 = 'whisper-1';

    // Anthropic Models
    case CLAUDE_3_5_SONNET = 'claude-3-5-sonnet-20240620';
    case CLAUDE_3_HAIKU = 'claude-3-haiku-20240307';
    case CLAUDE_3_OPUS = 'claude-3-opus-20240229';

    // Gemini Models
    case GEMINI_1_5_PRO = 'gemini-1.5-pro';
    case GEMINI_1_5_FLASH = 'gemini-1.5-flash';

    // Stable Diffusion Models
    case SD3_LARGE = 'sd3-large';
    case SD3_MEDIUM = 'sd3-medium';
    case SDXL_1024 = 'sdxl-1024-v1-0';

    // FAL AI Models
    case FAL_FLUX_PRO = 'fal-flux-pro';
    case FAL_FLUX_DEV = 'fal-flux-dev';
    case FAL_FLUX_SCHNELL = 'fal-flux-schnell';
    case FAL_SDXL = 'fal-sdxl';
    case FAL_SD3_MEDIUM = 'fal-sd3-medium';
    case FAL_STABLE_VIDEO = 'fal-stable-video';
    case FAL_ANIMATEDIFF = 'fal-animatediff';
    case FAL_LUMA_DREAM = 'fal-luma-dream';
    
    // Simplified aliases for common models
    case FLUX_PRO = 'flux-pro';
    case KLING_VIDEO = 'kling-video';
    case LUMA_DREAM_MACHINE = 'luma-dream-machine';

    // ElevenLabs Models
    case ELEVEN_MULTILINGUAL_V2 = 'eleven_multilingual_v2';

    // DeepSeek Models
    case DEEPSEEK_CHAT = 'deepseek-chat';
    case DEEPSEEK_REASONER = 'deepseek-reasoner';

    // Perplexity Models
    case PERPLEXITY_SONAR_LARGE = 'perplexity-sonar-large';
    case PERPLEXITY_SONAR_MEDIUM = 'perplexity-sonar-medium';
    case PERPLEXITY_SONAR_SMALL = 'perplexity-sonar-small';

    // Serper Models
    case SERPER_SEARCH = 'serper-search';
    case SERPER_NEWS = 'serper-news';
    case SERPER_IMAGES = 'serper-images';

    // Unsplash Models
    case UNSPLASH_SEARCH = 'unsplash-search';

    // Plagiarism Check Models
    case PLAGIARISM_BASIC = 'plagiarism-basic';
    case PLAGIARISM_ADVANCED = 'plagiarism-advanced';
    case PLAGIARISM_ACADEMIC = 'plagiarism-academic';

    // Midjourney Models
    case MIDJOURNEY_V6 = 'midjourney-v6';
    case MIDJOURNEY_V5 = 'midjourney-v5';
    case MIDJOURNEY_NIJI = 'midjourney-niji';

    // Azure Cognitive Services Models
    case AZURE_TTS = 'azure-tts';
    case AZURE_STT = 'azure-stt';
    case AZURE_TRANSLATOR = 'azure-translator';
    case AZURE_TEXT_ANALYTICS = 'azure-text-analytics';

    // OpenRouter Models (Latest models available through OpenRouter)
    // GPT-5 Models (Latest Generation - August 2025)
    case OPENROUTER_GPT_5 = 'openai/gpt-5';
    case OPENROUTER_GPT_5_MINI = 'openai/gpt-5-mini';
    case OPENROUTER_GPT_5_NANO = 'openai/gpt-5-nano';
    
    // GPT-4o Models
    case OPENROUTER_GPT_4O = 'openai/gpt-4o';
    case OPENROUTER_GPT_4O_2024_11_20 = 'openai/gpt-4o-2024-11-20';
    case OPENROUTER_GPT_4O_MINI = 'openai/gpt-4o-mini';
    case OPENROUTER_GPT_4O_MINI_2024_07_18 = 'openai/gpt-4o-mini-2024-07-18';
    
    // Claude 4 Models (Latest generation)
    case OPENROUTER_CLAUDE_4_OPUS = 'anthropic/claude-4-opus';
    case OPENROUTER_CLAUDE_4_SONNET = 'anthropic/claude-4-sonnet';
    
    // Claude 3.5 Models
    case OPENROUTER_CLAUDE_3_5_SONNET = 'anthropic/claude-3.5-sonnet';
    case OPENROUTER_CLAUDE_3_5_SONNET_20241022 = 'anthropic/claude-3.5-sonnet-20241022';
    case OPENROUTER_CLAUDE_3_5_HAIKU = 'anthropic/claude-3.5-haiku';
    
    // Claude 3 Models
    case OPENROUTER_CLAUDE_3_OPUS = 'anthropic/claude-3-opus';
    case OPENROUTER_CLAUDE_3_HAIKU = 'anthropic/claude-3-haiku';
    
    // Google Models
    // Gemini 2.5 Models (Latest Generation - March 2025)
    case OPENROUTER_GEMINI_2_5_PRO = 'google/gemini-2.5-pro';
    case OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL = 'google/gemini-2.5-pro-experimental';
    
    // Previous Gemini Models
    case OPENROUTER_GEMINI_PRO = 'google/gemini-pro';
    case OPENROUTER_GEMINI_1_5_PRO = 'google/gemini-1.5-pro';
    case OPENROUTER_GEMINI_2_0_FLASH = 'google/gemini-2.0-flash';
    
    // Meta Llama Models
    case OPENROUTER_LLAMA_3_1_405B = 'meta-llama/llama-3.1-405b-instruct';
    case OPENROUTER_LLAMA_3_1_70B = 'meta-llama/llama-3.1-70b-instruct';
    case OPENROUTER_LLAMA_3_2_90B = 'meta-llama/llama-3.2-90b-instruct';
    case OPENROUTER_LLAMA_3_3_70B = 'meta-llama/llama-3.3-70b-instruct';
    
    // Other Popular Models
    case OPENROUTER_MIXTRAL_8X7B = 'mistralai/mixtral-8x7b-instruct';
    case OPENROUTER_QWEN_2_5_72B = 'qwen/qwen-2.5-72b-instruct';
    case OPENROUTER_DEEPSEEK_V3 = 'deepseek/deepseek-chat';
    case OPENROUTER_DEEPSEEK_R1 = 'deepseek/deepseek-r1';
    
    // Free Models (OpenRouter Free Tier)
    case OPENROUTER_LLAMA_3_1_8B_FREE = 'meta-llama/llama-3.1-8b-instruct:free';
    case OPENROUTER_LLAMA_3_2_3B_FREE = 'meta-llama/llama-3.2-3b-instruct:free';
    case OPENROUTER_GEMMA_2_9B_FREE = 'google/gemma-2-9b-it:free';
    case OPENROUTER_MISTRAL_7B_FREE = 'mistralai/mistral-7b-instruct:free';
    case OPENROUTER_QWEN_2_5_7B_FREE = 'qwen/qwen-2.5-7b-instruct:free';
    case OPENROUTER_PHI_3_MINI_FREE = 'microsoft/phi-3-mini-128k-instruct:free';
    case OPENROUTER_OPENCHAT_3_5_FREE = 'openchat/openchat-3.5-1210:free';
    case AZURE_COMPUTER_VISION = 'azure-computer-vision';

    /**
     * Get the engine this entity belongs to
     */
    public function engine(): EngineEnum
    {
        return match ($this) {
            self::GPT_4O, self::GPT_4O_MINI, self::GPT_3_5_TURBO,
            self::DALL_E_3, self::DALL_E_2, self::WHISPER_1 => EngineEnum::OPENAI,

            self::CLAUDE_3_5_SONNET, self::CLAUDE_3_HAIKU, self::CLAUDE_3_OPUS => EngineEnum::ANTHROPIC,

            self::GEMINI_1_5_PRO, self::GEMINI_1_5_FLASH => EngineEnum::GEMINI,

            self::SD3_LARGE, self::SD3_MEDIUM, self::SDXL_1024 => EngineEnum::STABLE_DIFFUSION,

            self::ELEVEN_MULTILINGUAL_V2 => EngineEnum::ELEVEN_LABS,

            self::FAL_FLUX_PRO, self::FAL_FLUX_DEV, self::FAL_FLUX_SCHNELL,
            self::FAL_SDXL, self::FAL_SD3_MEDIUM, self::FAL_STABLE_VIDEO,
            self::FAL_ANIMATEDIFF, self::FAL_LUMA_DREAM,
            self::FLUX_PRO, self::KLING_VIDEO, self::LUMA_DREAM_MACHINE => EngineEnum::FAL_AI,

            self::DEEPSEEK_CHAT, self::DEEPSEEK_REASONER => EngineEnum::DEEPSEEK,

            self::PERPLEXITY_SONAR_LARGE, self::PERPLEXITY_SONAR_MEDIUM, self::PERPLEXITY_SONAR_SMALL => EngineEnum::PERPLEXITY,

            self::SERPER_SEARCH, self::SERPER_NEWS, self::SERPER_IMAGES => EngineEnum::SERPER,

            self::UNSPLASH_SEARCH => EngineEnum::UNSPLASH,

            self::PLAGIARISM_BASIC, self::PLAGIARISM_ADVANCED, self::PLAGIARISM_ACADEMIC => EngineEnum::PLAGIARISM_CHECK,

            self::MIDJOURNEY_V6, self::MIDJOURNEY_V5, self::MIDJOURNEY_NIJI => EngineEnum::MIDJOURNEY,

            self::AZURE_TTS, self::AZURE_STT, self::AZURE_TRANSLATOR, 
            self::AZURE_TEXT_ANALYTICS, self::AZURE_COMPUTER_VISION => EngineEnum::AZURE,

            // OpenRouter models (latest 2025 models)
            self::OPENROUTER_GPT_5, self::OPENROUTER_GPT_5_MINI, self::OPENROUTER_GPT_5_NANO,
            self::OPENROUTER_GPT_4O, self::OPENROUTER_GPT_4O_2024_11_20, self::OPENROUTER_GPT_4O_MINI, self::OPENROUTER_GPT_4O_MINI_2024_07_18,
            self::OPENROUTER_CLAUDE_4_OPUS, self::OPENROUTER_CLAUDE_4_SONNET,
            self::OPENROUTER_CLAUDE_3_5_SONNET, self::OPENROUTER_CLAUDE_3_5_SONNET_20241022, self::OPENROUTER_CLAUDE_3_5_HAIKU,
            self::OPENROUTER_CLAUDE_3_OPUS, self::OPENROUTER_CLAUDE_3_HAIKU,
            self::OPENROUTER_GEMINI_2_5_PRO, self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
            self::OPENROUTER_GEMINI_PRO, self::OPENROUTER_GEMINI_1_5_PRO, self::OPENROUTER_GEMINI_2_0_FLASH,
            self::OPENROUTER_LLAMA_3_1_405B, self::OPENROUTER_LLAMA_3_1_70B, self::OPENROUTER_LLAMA_3_2_90B, self::OPENROUTER_LLAMA_3_3_70B,
            self::OPENROUTER_MIXTRAL_8X7B, self::OPENROUTER_QWEN_2_5_72B, self::OPENROUTER_DEEPSEEK_V3, self::OPENROUTER_DEEPSEEK_R1,
            // Free models
            self::OPENROUTER_LLAMA_3_1_8B_FREE, self::OPENROUTER_LLAMA_3_2_3B_FREE, self::OPENROUTER_GEMMA_2_9B_FREE,
            self::OPENROUTER_MISTRAL_7B_FREE, self::OPENROUTER_QWEN_2_5_7B_FREE, self::OPENROUTER_PHI_3_MINI_FREE, self::OPENROUTER_OPENCHAT_3_5_FREE => EngineEnum::OPENROUTER,
        };
    }

    /**
     * Get the driver class for this entity
     */
    public function driverClass(): string
    {
        return match ($this) {
            self::GPT_4O => GPT4ODriver::class,
            self::GPT_4O_MINI => GPT4OMiniDriver::class,
            self::GPT_3_5_TURBO => GPT35TurboDriver::class,
            self::DALL_E_3 => DallE3Driver::class,
            self::DALL_E_2 => DallE2Driver::class,
            self::WHISPER_1 => WhisperDriver::class,

            self::CLAUDE_3_5_SONNET => Claude35SonnetDriver::class,
            self::CLAUDE_3_HAIKU => Claude3HaikuDriver::class,
            self::CLAUDE_3_OPUS => Claude3OpusDriver::class,

            self::GEMINI_1_5_PRO => Gemini15ProDriver::class,
            self::GEMINI_1_5_FLASH => Gemini15FlashDriver::class,

            self::SD3_LARGE => SD3LargeDriver::class,
            self::SDXL_1024 => SDXL1024Driver::class,

            self::ELEVEN_MULTILINGUAL_V2 => MultilingualV2Driver::class,

            self::FAL_FLUX_PRO, self::FLUX_PRO => FluxProDriver::class,
            self::FAL_LUMA_DREAM, self::KLING_VIDEO, self::LUMA_DREAM_MACHINE => KlingVideoDriver::class,

            self::DEEPSEEK_CHAT => DeepSeekChatDriver::class,
            self::DEEPSEEK_REASONER => DeepSeekReasonerDriver::class,

            // OpenRouter models use generic OpenAI-compatible drivers
            // GPT-5 Models (Latest Generation)
            self::OPENROUTER_GPT_5 => GPT4ODriver::class, // Use GPT-4o driver for compatibility
            self::OPENROUTER_GPT_5_MINI => GPT4OMiniDriver::class, // Use GPT-4o Mini driver
            self::OPENROUTER_GPT_5_NANO => GPT4OMiniDriver::class, // Use GPT-4o Mini driver for nano
            
            // GPT-4o Models
            self::OPENROUTER_GPT_4O => GPT4ODriver::class,
            self::OPENROUTER_GPT_4O_2024_11_20 => GPT4ODriver::class,
            self::OPENROUTER_GPT_4O_MINI => GPT4OMiniDriver::class,
            self::OPENROUTER_GPT_4O_MINI_2024_07_18 => GPT4OMiniDriver::class,
            
            // Claude 4 Models
            self::OPENROUTER_CLAUDE_4_OPUS => Claude3OpusDriver::class, // Use existing Claude driver
            self::OPENROUTER_CLAUDE_4_SONNET => Claude35SonnetDriver::class, // Use existing Claude driver
            
            // Claude 3.5 Models
            self::OPENROUTER_CLAUDE_3_5_SONNET => Claude35SonnetDriver::class,
            self::OPENROUTER_CLAUDE_3_5_SONNET_20241022 => Claude35SonnetDriver::class,
            self::OPENROUTER_CLAUDE_3_5_HAIKU => Claude3HaikuDriver::class,
            
            // Claude 3 Models
            self::OPENROUTER_CLAUDE_3_OPUS => Claude3OpusDriver::class,
            self::OPENROUTER_CLAUDE_3_HAIKU => Claude3HaikuDriver::class,
            
            // Google Models
            // Gemini 2.5 Models (Latest Generation)
            self::OPENROUTER_GEMINI_2_5_PRO => Gemini15ProDriver::class, // Use existing Gemini driver
            self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL => Gemini15ProDriver::class, // Use existing Gemini driver
            
            // Previous Gemini Models
            self::OPENROUTER_GEMINI_PRO => Gemini15ProDriver::class,
            self::OPENROUTER_GEMINI_1_5_PRO => Gemini15ProDriver::class,
            self::OPENROUTER_GEMINI_2_0_FLASH => Gemini15FlashDriver::class,
            
            // Meta Llama Models
            self::OPENROUTER_LLAMA_3_1_405B => GPT4ODriver::class, // Generic chat driver
            self::OPENROUTER_LLAMA_3_1_70B => GPT4ODriver::class, // Generic chat driver
            self::OPENROUTER_LLAMA_3_2_90B => GPT4ODriver::class, // Generic chat driver
            self::OPENROUTER_LLAMA_3_3_70B => GPT4ODriver::class, // Generic chat driver
            
            // Other Models
            self::OPENROUTER_MIXTRAL_8X7B => GPT4ODriver::class, // Generic chat driver
            self::OPENROUTER_QWEN_2_5_72B => GPT4ODriver::class, // Generic chat driver
            self::OPENROUTER_DEEPSEEK_V3 => DeepSeekChatDriver::class,
            self::OPENROUTER_DEEPSEEK_R1 => DeepSeekChatDriver::class,
            
            // Free Models (use generic chat drivers)
            self::OPENROUTER_LLAMA_3_1_8B_FREE => GPT4ODriver::class,
            self::OPENROUTER_LLAMA_3_2_3B_FREE => GPT4ODriver::class,
            self::OPENROUTER_GEMMA_2_9B_FREE => GPT4ODriver::class,
            self::OPENROUTER_MISTRAL_7B_FREE => GPT4ODriver::class,
            self::OPENROUTER_QWEN_2_5_7B_FREE => GPT4ODriver::class,
            self::OPENROUTER_PHI_3_MINI_FREE => GPT4ODriver::class,
            self::OPENROUTER_OPENCHAT_3_5_FREE => GPT4ODriver::class,
        };
    }

    /**
     * Get the display label for this entity
     */
    public function label(): string
    {
        return match ($this) {
            self::GPT_4O => 'GPT-4o',
            self::GPT_4O_MINI => 'GPT-4o Mini',
            self::GPT_3_5_TURBO => 'GPT-3.5 Turbo',
            self::DALL_E_3 => 'DALL-E 3',
            self::DALL_E_2 => 'DALL-E 2',
            self::WHISPER_1 => 'Whisper',

            self::CLAUDE_3_5_SONNET => 'Claude 3.5 Sonnet',
            self::CLAUDE_3_HAIKU => 'Claude 3 Haiku',
            self::CLAUDE_3_OPUS => 'Claude 3 Opus',

            self::GEMINI_1_5_PRO => 'Gemini 1.5 Pro',
            self::GEMINI_1_5_FLASH => 'Gemini 1.5 Flash',

            self::SD3_LARGE => 'Stable Diffusion 3 Large',
            self::SD3_MEDIUM => 'Stable Diffusion 3 Medium',
            self::SDXL_1024 => 'Stable Diffusion XL',

            self::ELEVEN_MULTILINGUAL_V2 => 'ElevenLabs Multilingual v2',

            self::FLUX_PRO => 'Flux Pro',
            self::KLING_VIDEO => 'Kling Video',
            self::LUMA_DREAM_MACHINE => 'Luma Dream Machine',

            self::DEEPSEEK_CHAT => 'DeepSeek Chat',
            self::DEEPSEEK_REASONER => 'DeepSeek Reasoner',

            // OpenRouter Models
            // GPT-5 Models (Latest Generation - August 2025)
            self::OPENROUTER_GPT_5 => 'GPT-5 (OpenRouter)',
            self::OPENROUTER_GPT_5_MINI => 'GPT-5 Mini (OpenRouter)',
            self::OPENROUTER_GPT_5_NANO => 'GPT-5 Nano (OpenRouter)',
            
            // GPT-4o Models
            self::OPENROUTER_GPT_4O => 'GPT-4o (OpenRouter)',
            self::OPENROUTER_GPT_4O_2024_11_20 => 'GPT-4o (2024-11-20) (OpenRouter)',
            self::OPENROUTER_GPT_4O_MINI => 'GPT-4o Mini (OpenRouter)',
            self::OPENROUTER_GPT_4O_MINI_2024_07_18 => 'GPT-4o Mini (2024-07-18) (OpenRouter)',
            
            // Claude 4 Models
            self::OPENROUTER_CLAUDE_4_OPUS => 'Claude 4 Opus (OpenRouter)',
            self::OPENROUTER_CLAUDE_4_SONNET => 'Claude 4 Sonnet (OpenRouter)',
            
            // Claude 3.5 Models
            self::OPENROUTER_CLAUDE_3_5_SONNET => 'Claude 3.5 Sonnet (OpenRouter)',
            self::OPENROUTER_CLAUDE_3_5_SONNET_20241022 => 'Claude 3.5 Sonnet (2024-10-22) (OpenRouter)',
            self::OPENROUTER_CLAUDE_3_5_HAIKU => 'Claude 3.5 Haiku (OpenRouter)',
            
            // Claude 3 Models
            self::OPENROUTER_CLAUDE_3_OPUS => 'Claude 3 Opus (OpenRouter)',
            self::OPENROUTER_CLAUDE_3_HAIKU => 'Claude 3 Haiku (OpenRouter)',
            
            // Google Models
            // Gemini 2.5 Models (Latest Generation - March 2025)
            self::OPENROUTER_GEMINI_2_5_PRO => 'Gemini 2.5 Pro (OpenRouter)',
            self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL => 'Gemini 2.5 Pro Experimental (OpenRouter)',
            
            // Previous Gemini Models
            self::OPENROUTER_GEMINI_PRO => 'Gemini Pro (OpenRouter)',
            self::OPENROUTER_GEMINI_1_5_PRO => 'Gemini 1.5 Pro (OpenRouter)',
            self::OPENROUTER_GEMINI_2_0_FLASH => 'Gemini 2.0 Flash (OpenRouter)',
            
            // Meta Llama Models
            self::OPENROUTER_LLAMA_3_1_405B => 'Llama 3.1 405B (OpenRouter)',
            self::OPENROUTER_LLAMA_3_1_70B => 'Llama 3.1 70B (OpenRouter)',
            self::OPENROUTER_LLAMA_3_2_90B => 'Llama 3.2 90B (OpenRouter)',
            self::OPENROUTER_LLAMA_3_3_70B => 'Llama 3.3 70B (OpenRouter)',
            
            // Other Models
            self::OPENROUTER_MIXTRAL_8X7B => 'Mixtral 8x7B (OpenRouter)',
            self::OPENROUTER_QWEN_2_5_72B => 'Qwen 2.5 72B (OpenRouter)',
            self::OPENROUTER_DEEPSEEK_V3 => 'DeepSeek V3 (OpenRouter)',
            self::OPENROUTER_DEEPSEEK_R1 => 'DeepSeek R1 (OpenRouter)',
            
            // Free Models
            self::OPENROUTER_LLAMA_3_1_8B_FREE => 'Llama 3.1 8B (Free)',
            self::OPENROUTER_LLAMA_3_2_3B_FREE => 'Llama 3.2 3B (Free)',
            self::OPENROUTER_GEMMA_2_9B_FREE => 'Gemma 2 9B (Free)',
            self::OPENROUTER_MISTRAL_7B_FREE => 'Mistral 7B (Free)',
            self::OPENROUTER_QWEN_2_5_7B_FREE => 'Qwen 2.5 7B (Free)',
            self::OPENROUTER_PHI_3_MINI_FREE => 'Phi-3 Mini (Free)',
            self::OPENROUTER_OPENCHAT_3_5_FREE => 'OpenChat 3.5 (Free)',
        };
    }

    /**
     * Get the credit index (cost multiplier) for this entity
     */
    public function creditIndex(): float
    {
        return match ($this) {
            self::GPT_4O => 2.0,
            self::GPT_4O_MINI => 0.5,
            self::GPT_3_5_TURBO => 0.3,
            self::DALL_E_3 => 5.0,
            self::DALL_E_2 => 3.0,
            self::WHISPER_1 => 1.0,

            self::CLAUDE_3_5_SONNET => 1.8,
            self::CLAUDE_3_HAIKU => 0.8,
            self::CLAUDE_3_OPUS => 3.0,

            self::GEMINI_1_5_PRO => 1.5,
            self::GEMINI_1_5_FLASH => 0.4,

            self::SD3_LARGE => 4.0,
            self::SD3_MEDIUM => 3.0,
            self::SDXL_1024 => 2.5,

            self::ELEVEN_MULTILINGUAL_V2 => 2.0,

            self::FAL_FLUX_PRO, self::FLUX_PRO => 3.5,
            self::FAL_FLUX_DEV => 2.5,
            self::FAL_FLUX_SCHNELL => 1.5,
            self::FAL_SDXL => 2.0,
            self::FAL_SD3_MEDIUM => 2.5,
            self::FAL_STABLE_VIDEO, self::FAL_ANIMATEDIFF => 5.0,
            self::FAL_LUMA_DREAM, self::KLING_VIDEO, self::LUMA_DREAM_MACHINE => 8.0,

            self::DEEPSEEK_CHAT => 0.2,
            self::DEEPSEEK_REASONER => 0.4,

            self::PERPLEXITY_SONAR_LARGE => 1.2,
            self::PERPLEXITY_SONAR_MEDIUM => 0.8,
            self::PERPLEXITY_SONAR_SMALL => 0.4,

            self::SERPER_SEARCH => 0.1,
            self::SERPER_NEWS => 0.1,
            self::SERPER_IMAGES => 0.1,

            self::UNSPLASH_SEARCH => 0.05,

            self::PLAGIARISM_BASIC => 0.5,
            self::PLAGIARISM_ADVANCED => 1.0,
            self::PLAGIARISM_ACADEMIC => 1.5,

            self::MIDJOURNEY_V6 => 4.0,
            self::MIDJOURNEY_V5 => 3.5,
            self::MIDJOURNEY_NIJI => 3.0,

            self::AZURE_TTS => 1.0,
            self::AZURE_STT => 1.0,
            self::AZURE_TRANSLATOR => 0.3,
            self::AZURE_TEXT_ANALYTICS => 0.5,
            self::AZURE_COMPUTER_VISION => 1.5,

            // OpenRouter Models - competitive pricing through unified API
            // GPT-5 Models (Latest Generation - Premium Pricing)
            self::OPENROUTER_GPT_5 => 5.0, // Premium GPT-5 pricing
            self::OPENROUTER_GPT_5_MINI => 2.5, // GPT-5 Mini pricing
            self::OPENROUTER_GPT_5_NANO => 1.0, // GPT-5 Nano pricing
            
            // GPT-4o Models
            self::OPENROUTER_GPT_4O => 2.2, // Slightly higher due to routing overhead
            self::OPENROUTER_GPT_4O_2024_11_20 => 2.3, // Latest GPT-4o version
            self::OPENROUTER_GPT_4O_MINI => 0.6,
            self::OPENROUTER_GPT_4O_MINI_2024_07_18 => 0.6,
            
            // Claude 4 Models - Premium pricing for latest generation
            self::OPENROUTER_CLAUDE_4_OPUS => 4.5, // Premium Claude 4 model
            self::OPENROUTER_CLAUDE_4_SONNET => 3.5, // Claude 4 Sonnet
            
            // Claude 3.5 Models
            self::OPENROUTER_CLAUDE_3_5_SONNET => 2.0,
            self::OPENROUTER_CLAUDE_3_5_SONNET_20241022 => 2.1, // Latest version premium
            self::OPENROUTER_CLAUDE_3_5_HAIKU => 1.0,
            
            // Claude 3 Models
            self::OPENROUTER_CLAUDE_3_OPUS => 3.2, // Premium Claude model
            self::OPENROUTER_CLAUDE_3_HAIKU => 0.9,
            
            // Google Models
            // Gemini 2.5 Models (Latest Generation - Premium Pricing)
            self::OPENROUTER_GEMINI_2_5_PRO => 3.0, // Premium Gemini 2.5 Pro with thinking
            self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL => 3.2, // Experimental version premium
            
            // Previous Gemini Models
            self::OPENROUTER_GEMINI_PRO => 1.7,
            self::OPENROUTER_GEMINI_1_5_PRO => 1.8, // Newer version
            self::OPENROUTER_GEMINI_2_0_FLASH => 1.9, // Latest Gemini
            
            // Meta Llama Models
            self::OPENROUTER_LLAMA_3_1_405B => 3.0, // Premium large model
            self::OPENROUTER_LLAMA_3_1_70B => 1.2,
            self::OPENROUTER_LLAMA_3_2_90B => 1.4, // Newer Llama version
            self::OPENROUTER_LLAMA_3_3_70B => 1.3, // Latest Llama 3.3
            
            // Other Models
            self::OPENROUTER_MIXTRAL_8X7B => 0.8,
            self::OPENROUTER_QWEN_2_5_72B => 1.0,
            self::OPENROUTER_DEEPSEEK_V3 => 0.3,
            self::OPENROUTER_DEEPSEEK_R1 => 0.4, // Latest DeepSeek R1
            
            // Free Models (0.0 credit cost)
            self::OPENROUTER_LLAMA_3_1_8B_FREE => 0.0,
            self::OPENROUTER_LLAMA_3_2_3B_FREE => 0.0,
            self::OPENROUTER_GEMMA_2_9B_FREE => 0.0,
            self::OPENROUTER_MISTRAL_7B_FREE => 0.0,
            self::OPENROUTER_QWEN_2_5_7B_FREE => 0.0,
            self::OPENROUTER_PHI_3_MINI_FREE => 0.0,
            self::OPENROUTER_OPENCHAT_3_5_FREE => 0.0,
        };
    }

    /**
     * Get the content type this entity processes
     */
    public function contentType(): string
    {
        return $this->getContentType();
    }

    /**
     * Get the content type this entity processes
     */
    public function getContentType(): string
    {
        return match ($this) {
            self::GPT_4O, self::GPT_4O_MINI, self::GPT_3_5_TURBO,
            self::CLAUDE_3_5_SONNET, self::CLAUDE_3_HAIKU, self::CLAUDE_3_OPUS,
            self::GEMINI_1_5_PRO, self::GEMINI_1_5_FLASH,
            self::DEEPSEEK_CHAT, self::DEEPSEEK_REASONER,
            // All OpenRouter models are text-based (including latest 2025 models)
            self::OPENROUTER_GPT_5, self::OPENROUTER_GPT_5_MINI, self::OPENROUTER_GPT_5_NANO,
            self::OPENROUTER_GPT_4O, self::OPENROUTER_GPT_4O_2024_11_20, self::OPENROUTER_GPT_4O_MINI, self::OPENROUTER_GPT_4O_MINI_2024_07_18,
            self::OPENROUTER_CLAUDE_4_OPUS, self::OPENROUTER_CLAUDE_4_SONNET,
            self::OPENROUTER_CLAUDE_3_5_SONNET, self::OPENROUTER_CLAUDE_3_5_SONNET_20241022, self::OPENROUTER_CLAUDE_3_5_HAIKU,
            self::OPENROUTER_CLAUDE_3_OPUS, self::OPENROUTER_CLAUDE_3_HAIKU,
            self::OPENROUTER_GEMINI_2_5_PRO, self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
            self::OPENROUTER_GEMINI_PRO, self::OPENROUTER_GEMINI_1_5_PRO, self::OPENROUTER_GEMINI_2_0_FLASH,
            self::OPENROUTER_LLAMA_3_1_405B, self::OPENROUTER_LLAMA_3_1_70B, self::OPENROUTER_LLAMA_3_2_90B, self::OPENROUTER_LLAMA_3_3_70B,
            self::OPENROUTER_MIXTRAL_8X7B, self::OPENROUTER_QWEN_2_5_72B, self::OPENROUTER_DEEPSEEK_V3, self::OPENROUTER_DEEPSEEK_R1,
            // Free models are also text-based
            self::OPENROUTER_LLAMA_3_1_8B_FREE, self::OPENROUTER_LLAMA_3_2_3B_FREE, self::OPENROUTER_GEMMA_2_9B_FREE,
            self::OPENROUTER_MISTRAL_7B_FREE, self::OPENROUTER_QWEN_2_5_7B_FREE, self::OPENROUTER_PHI_3_MINI_FREE, self::OPENROUTER_OPENCHAT_3_5_FREE => 'text',

            self::DALL_E_3, self::DALL_E_2, self::SD3_LARGE, self::SD3_MEDIUM,
            self::SDXL_1024, self::FAL_FLUX_PRO, self::FLUX_PRO,
            self::FAL_FLUX_DEV, self::FAL_FLUX_SCHNELL, self::FAL_SDXL, self::FAL_SD3_MEDIUM => 'image',

            self::FAL_STABLE_VIDEO, self::FAL_ANIMATEDIFF, self::FAL_LUMA_DREAM,
            self::KLING_VIDEO, self::LUMA_DREAM_MACHINE => 'video',

            self::WHISPER_1, self::ELEVEN_MULTILINGUAL_V2 => 'audio',

            self::PERPLEXITY_SONAR_LARGE, self::PERPLEXITY_SONAR_MEDIUM, self::PERPLEXITY_SONAR_SMALL,
            self::SERPER_SEARCH, self::SERPER_NEWS, self::SERPER_IMAGES,
            self::UNSPLASH_SEARCH => 'search',

            self::PLAGIARISM_BASIC, self::PLAGIARISM_ADVANCED, self::PLAGIARISM_ACADEMIC => 'plagiarism',

            self::MIDJOURNEY_V6, self::MIDJOURNEY_V5, self::MIDJOURNEY_NIJI => 'image',

            self::AZURE_TTS => 'audio',
            self::AZURE_STT => 'audio',
            self::AZURE_TRANSLATOR => 'text',
            self::AZURE_TEXT_ANALYTICS => 'text',
            self::AZURE_COMPUTER_VISION => 'image',
        };
    }

    /**
     * Get maximum tokens for this model
     */
    public function maxTokens(): int
    {
        return match ($this) {
            self::GPT_4O => 128000,
            self::GPT_4O_MINI => 128000,
            self::GPT_3_5_TURBO => 16385,
            
            self::CLAUDE_3_5_SONNET => 200000,
            self::CLAUDE_3_HAIKU => 200000,
            self::CLAUDE_3_OPUS => 200000,
            
            self::GEMINI_1_5_PRO => 2097152,
            self::GEMINI_1_5_FLASH => 1048576,
            
            self::DEEPSEEK_CHAT => 32768,
            self::DEEPSEEK_REASONER => 65536,
            
            default => 4096,
        };
    }

    /**
     * Check if this model supports vision/image input
     */
    public function supportsVision(): bool
    {
        return match ($this) {
            self::GPT_4O,
            self::CLAUDE_3_5_SONNET, self::CLAUDE_3_OPUS,
            self::GEMINI_1_5_PRO, self::GEMINI_1_5_FLASH => true,
            default => false,
        };
    }

    /**
     * Check if this model supports streaming
     */
    public function supportsStreaming(): bool
    {
        return match ($this) {
            self::GPT_4O, self::GPT_4O_MINI, self::GPT_3_5_TURBO,
            self::CLAUDE_3_5_SONNET, self::CLAUDE_3_HAIKU, self::CLAUDE_3_OPUS,
            self::GEMINI_1_5_PRO, self::GEMINI_1_5_FLASH,
            self::DEEPSEEK_CHAT, self::DEEPSEEK_REASONER => true,
            default => false,
        };
    }

    /**
     * Get calculation method for credits
     */
    public function calculationMethod(): string
    {
        return match ($this->contentType()) {
            'text' => 'words',
            'image' => 'images',
            'video' => 'videos',
            'audio' => 'minutes',
            'search' => 'queries',
            'plagiarism' => 'documents',
            default => 'units',
        };
    }

    /**
     * Get tooltip explaining how credits are calculated
     */
    public function tooltipHowToCalc(): string
    {
        return match ($this->calculationMethod()) {
            'words' => '1 Credit = 1 Word',
            'images' => '1 Credit = 1 Image',
            'videos' => '1 Credit = 1 Video',
            'minutes' => '1 Credit = 1 Minute',
            'queries' => '1 Credit = 1 Query',
            'documents' => '1 Credit = 1 Document',
            default => '1 Credit = 1 Unit',
        };
    }

    /**
     * Get the slug for this entity
     */
    public function slug(): string
    {
        return $this->value;
    }

    /**
     * Create entity from slug
     */
    public static function fromSlug(string $slug): self
    {
        return self::from($slug);
    }

    /**
     * Get all entities for a specific engine
     */
    public static function forEngine(EngineEnum $engine): array
    {
        return array_filter(
            self::cases(),
            fn(self $entity) => $entity->engine() === $engine
        );
    }

    /**
     * Get all entities of a specific content type
     */
    public static function forContentType(string $contentType): array
    {
        return array_filter(
            self::cases(),
            fn(self $entity) => $entity->contentType() === $contentType
        );
    }
}
