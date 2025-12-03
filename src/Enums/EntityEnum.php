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
use LaravelAIEngine\Services\Models\DynamicModelResolver;

class EntityEnum
{
    protected static ?DynamicModelResolver $resolver = null;
    protected ?array $dynamicModel = null;
    // OpenAI Models
    public const GPT_4O = 'gpt-4o';
    public const GPT_4O_MINI = 'gpt-4o-mini';
    public const GPT_3_5_TURBO = 'gpt-3.5-turbo';
    public const GPT_5 = 'gpt-5';
    public const GPT_5_MINI = 'gpt-5-mini';
    public const GPT_5_NANO = 'gpt-5-nano';
    public const DALL_E_3 = 'dall-e-3';
    public const DALL_E_2 = 'dall-e-2';
    public const WHISPER_1 = 'whisper-1';

    // Anthropic Models
    public const CLAUDE_3_5_SONNET = 'claude-3-5-sonnet-20240620';
    public const CLAUDE_3_HAIKU = 'claude-3-haiku-20240307';
    public const CLAUDE_3_OPUS = 'claude-3-opus-20240229';

    // Gemini Models
    public const GEMINI_1_5_PRO = 'gemini-1.5-pro';
    public const GEMINI_1_5_FLASH = 'gemini-1.5-flash';

    // Stable Diffusion Models
    public const SD3_LARGE = 'sd3-large';
    public const SD3_MEDIUM = 'sd3-medium';
    public const SDXL_1024 = 'sdxl-1024-v1-0';

    // FAL AI Models
    public const FAL_FLUX_PRO = 'fal-flux-pro';
    public const FAL_FLUX_DEV = 'fal-flux-dev';
    public const FAL_FLUX_SCHNELL = 'fal-flux-schnell';
    public const FAL_SDXL = 'fal-sdxl';
    public const FAL_SD3_MEDIUM = 'fal-sd3-medium';
    public const FAL_STABLE_VIDEO = 'fal-stable-video';
    public const FAL_ANIMATEDIFF = 'fal-animatediff';
    public const FAL_LUMA_DREAM = 'fal-luma-dream';

    // Simplified aliases for common models
    public const FLUX_PRO = 'flux-pro';
    public const KLING_VIDEO = 'kling-video';
    public const LUMA_DREAM_MACHINE = 'luma-dream-machine';

    // ElevenLabs Models
    public const ELEVEN_MULTILINGUAL_V2 = 'eleven_multilingual_v2';

    // DeepSeek Models
    public const DEEPSEEK_CHAT = 'deepseek-chat';
    public const DEEPSEEK_REASONER = 'deepseek-reasoner';

    // Perplexity Models
    public const PERPLEXITY_SONAR_LARGE = 'perplexity-sonar-large';
    public const PERPLEXITY_SONAR_MEDIUM = 'perplexity-sonar-medium';
    public const PERPLEXITY_SONAR_SMALL = 'perplexity-sonar-small';

    // Serper Models
    public const SERPER_SEARCH = 'serper-search';
    public const SERPER_NEWS = 'serper-news';
    public const SERPER_IMAGES = 'serper-images';

    // Unsplash Models
    public const UNSPLASH_SEARCH = 'unsplash-search';

    // Plagiarism Check Models
    public const PLAGIARISM_BASIC = 'plagiarism-basic';
    public const PLAGIARISM_ADVANCED = 'plagiarism-advanced';
    public const PLAGIARISM_ACADEMIC = 'plagiarism-academic';

    // Midjourney Models
    public const MIDJOURNEY_V6 = 'midjourney-v6';
    public const MIDJOURNEY_V5 = 'midjourney-v5';
    public const MIDJOURNEY_NIJI = 'midjourney-niji';

    // Azure Cognitive Services Models
    public const AZURE_TTS = 'azure-tts';
    public const AZURE_STT = 'azure-stt';
    public const AZURE_TRANSLATOR = 'azure-translator';
    public const AZURE_TEXT_ANALYTICS = 'azure-text-analytics';
    public const AZURE_COMPUTER_VISION = 'azure-computer-vision';

    // Google TTS
    public const GOOGLE_TTS = 'google-tts';

    // OpenRouter Models (Latest models available through OpenRouter)
    // GPT-5 Models (Latest Generation - August 2025)
    public const OPENROUTER_GPT_5 = 'openai/gpt-5';
    public const OPENROUTER_GPT_5_MINI = 'openai/gpt-5-mini';
    public const OPENROUTER_GPT_5_NANO = 'openai/gpt-5-nano';

    // GPT-4o Models
    public const OPENROUTER_GPT_4O = 'openai/gpt-4o';
    public const OPENROUTER_GPT_4O_2024_11_20 = 'openai/gpt-4o-2024-11-20';
    public const OPENROUTER_GPT_4O_MINI = 'openai/gpt-4o-mini';
    public const OPENROUTER_GPT_4O_MINI_2024_07_18 = 'openai/gpt-4o-mini-2024-07-18';

    // Claude 4 Models (Latest generation)
    public const OPENROUTER_CLAUDE_4_OPUS = 'anthropic/claude-4-opus';
    public const OPENROUTER_CLAUDE_4_SONNET = 'anthropic/claude-4-sonnet';

    // Claude 3.5 Models
    public const OPENROUTER_CLAUDE_3_5_SONNET = 'anthropic/claude-3.5-sonnet';
    public const OPENROUTER_CLAUDE_3_5_SONNET_20241022 = 'anthropic/claude-3.5-sonnet-20241022';
    public const OPENROUTER_CLAUDE_3_5_HAIKU = 'anthropic/claude-3.5-haiku';

    // Claude 3 Models
    public const OPENROUTER_CLAUDE_3_OPUS = 'anthropic/claude-3-opus';
    public const OPENROUTER_CLAUDE_3_HAIKU = 'anthropic/claude-3-haiku';

    // Google Models
    // Gemini 2.5 Models (Latest Generation - March 2025)
    public const OPENROUTER_GEMINI_2_5_PRO = 'google/gemini-2.5-pro';
    public const OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL = 'google/gemini-2.5-pro-experimental';

    // Previous Gemini Models
    public const OPENROUTER_GEMINI_PRO = 'google/gemini-pro';
    public const OPENROUTER_GEMINI_1_5_PRO = 'google/gemini-1.5-pro';
    public const OPENROUTER_GEMINI_2_0_FLASH = 'google/gemini-2.0-flash';

    // Meta Llama Models
    public const OPENROUTER_LLAMA_3_1_405B = 'meta-llama/llama-3.1-405b-instruct';
    public const OPENROUTER_LLAMA_3_1_70B = 'meta-llama/llama-3.1-70b-instruct';
    public const OPENROUTER_LLAMA_3_2_90B = 'meta-llama/llama-3.2-90b-instruct';
    public const OPENROUTER_LLAMA_3_3_70B = 'meta-llama/llama-3.3-70b-instruct';

    // Other Popular Models
    public const OPENROUTER_MIXTRAL_8X7B = 'mistralai/mixtral-8x7b-instruct';
    public const OPENROUTER_QWEN_2_5_72B = 'qwen/qwen-2.5-72b-instruct';
    public const OPENROUTER_DEEPSEEK_V3 = 'deepseek/deepseek-chat';

    // Ollama Models (Local AI Models)
    // Llama Models
    public const OLLAMA_LLAMA2 = 'llama2';
    public const OLLAMA_LLAMA2_7B = 'llama2:7b';
    public const OLLAMA_LLAMA2_13B = 'llama2:13b';
    public const OLLAMA_LLAMA2_70B = 'llama2:70b';
    public const OLLAMA_LLAMA3 = 'llama3';
    public const OLLAMA_LLAMA3_8B = 'llama3:8b';
    public const OLLAMA_LLAMA3_70B = 'llama3:70b';
    public const OLLAMA_LLAMA3_1 = 'llama3.1';
    public const OLLAMA_LLAMA3_2 = 'llama3.2';
    
    // Mistral Models
    public const OLLAMA_MISTRAL = 'mistral';
    public const OLLAMA_MISTRAL_7B = 'mistral:7b';
    public const OLLAMA_MIXTRAL = 'mixtral';
    public const OLLAMA_MIXTRAL_8X7B = 'mixtral:8x7b';
    
    // Code Models
    public const OLLAMA_CODELLAMA = 'codellama';
    public const OLLAMA_CODELLAMA_7B = 'codellama:7b';
    public const OLLAMA_CODELLAMA_13B = 'codellama:13b';
    public const OLLAMA_CODELLAMA_34B = 'codellama:34b';
    
    // Other Popular Ollama Models
    public const OLLAMA_PHI = 'phi';
    public const OLLAMA_PHI_2 = 'phi:2.7b';
    public const OLLAMA_GEMMA = 'gemma';
    public const OLLAMA_GEMMA_2B = 'gemma:2b';
    public const OLLAMA_GEMMA_7B = 'gemma:7b';
    public const OLLAMA_NEURAL_CHAT = 'neural-chat';
    public const OLLAMA_STARLING = 'starling-lm';
    public const OLLAMA_ORCA_MINI = 'orca-mini';
    public const OLLAMA_VICUNA = 'vicuna';
    public const OLLAMA_NOUS_HERMES = 'nous-hermes';
    public const OLLAMA_WIZARD_CODER = 'wizardcoder';
    public const OLLAMA_DEEPSEEK_CODER = 'deepseek-coder';
    public const OLLAMA_QWEN = 'qwen';
    public const OLLAMA_SOLAR = 'solar';
    public const OPENROUTER_DEEPSEEK_R1 = 'deepseek/deepseek-r1';

    // Free Models (OpenRouter Free Tier)
    public const OPENROUTER_LLAMA_3_1_8B_FREE = 'meta-llama/llama-3.1-8b-instruct:free';
    public const OPENROUTER_LLAMA_3_2_3B_FREE = 'meta-llama/llama-3.2-3b-instruct:free';
    public const OPENROUTER_GEMMA_2_9B_FREE = 'google/gemma-2-9b-it:free';
    public const OPENROUTER_MISTRAL_7B_FREE = 'mistralai/mistral-7b-instruct:free';
    public const OPENROUTER_QWEN_2_5_7B_FREE = 'qwen/qwen-2.5-7b-instruct:free';
    public const OPENROUTER_PHI_3_MINI_FREE = 'microsoft/phi-3-mini-128k-instruct:free';
    public const OPENROUTER_OPENCHAT_3_5_FREE = 'openchat/openchat-3.5-1210:free';

    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
        
        // Try to load dynamic model info if not a predefined constant
        if (!$this->isPredefinedModel()) {
            $this->dynamicModel = $this->getResolver()->resolve($value);
        }
    }
    
    /**
     * Get or create the resolver instance
     */
    protected function getResolver(): DynamicModelResolver
    {
        if (static::$resolver === null) {
            static::$resolver = new DynamicModelResolver();
        }
        return static::$resolver;
    }
    
    /**
     * Check if this is a predefined model constant
     */
    protected function isPredefinedModel(): bool
    {
        $reflection = new \ReflectionClass(static::class);
        $constants = $reflection->getConstants();
        return in_array($this->value, $constants, true);
    }
    
    /**
     * Check if this model is loaded dynamically from database
     */
    public function isDynamic(): bool
    {
        return $this->dynamicModel !== null;
    }
    
    /**
     * Get dynamic model property or fallback to switch statement
     */
    protected function getDynamicOr(string $key, callable $fallback)
    {
        if ($this->isDynamic() && isset($this->dynamicModel[$key])) {
            return $this->dynamicModel[$key];
        }
        return $fallback();
    }


    /**
     * Get the engine this entity belongs to
     */
    public function engine(): EngineEnum
    {
        // Use dynamic model data if available
        if ($this->isDynamic() && isset($this->dynamicModel['engine'])) {
            return new EngineEnum($this->dynamicModel['engine']);
        }
        
        switch ($this->value) {
            case self::GPT_4O:
            case self::GPT_4O_MINI:
            case self::GPT_3_5_TURBO:
            case self::GPT_5:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
            case self::DALL_E_3:
            case self::DALL_E_2:
            case self::WHISPER_1:
                return new EngineEnum(EngineEnum::OPENAI);
            case self::CLAUDE_3_5_SONNET:
            case self::CLAUDE_3_HAIKU:
            case self::CLAUDE_3_OPUS:
                return new EngineEnum(EngineEnum::ANTHROPIC);
            case self::GEMINI_1_5_PRO:
            case self::GEMINI_1_5_FLASH:
                return new EngineEnum(EngineEnum::GEMINI);
            case self::SD3_LARGE:
            case self::SD3_MEDIUM:
            case self::SDXL_1024:
                return new EngineEnum(EngineEnum::STABLE_DIFFUSION);
            case self::ELEVEN_MULTILINGUAL_V2:
                return new EngineEnum(EngineEnum::ELEVEN_LABS);
            case self::FLUX_PRO:
            case self::KLING_VIDEO:
            case self::LUMA_DREAM_MACHINE:
                return new EngineEnum(EngineEnum::FAL_AI);
            case self::DEEPSEEK_CHAT:
            case self::DEEPSEEK_REASONER:
                return new EngineEnum(EngineEnum::DEEPSEEK);
            case self::PERPLEXITY_SONAR_LARGE:
            case self::PERPLEXITY_SONAR_MEDIUM:
            case self::PERPLEXITY_SONAR_SMALL:
                return new EngineEnum(EngineEnum::PERPLEXITY);
            case self::SERPER_SEARCH:
            case self::SERPER_NEWS:
            case self::SERPER_IMAGES:
                return new EngineEnum(EngineEnum::SERPER);
            case self::UNSPLASH_SEARCH:
                return new EngineEnum(EngineEnum::UNSPLASH);
            case self::PLAGIARISM_BASIC:
            case self::PLAGIARISM_ADVANCED:
            case self::PLAGIARISM_ACADEMIC:
                return new EngineEnum(EngineEnum::PLAGIARISM_CHECK);
            case self::MIDJOURNEY_V6:
            case self::MIDJOURNEY_V5:
            case self::MIDJOURNEY_NIJI:
                return new EngineEnum(EngineEnum::MIDJOURNEY);
            case self::AZURE_TEXT_ANALYTICS:
            case self::AZURE_COMPUTER_VISION:
                return new EngineEnum(EngineEnum::AZURE);
            case self::OPENROUTER_MISTRAL_7B_FREE:
            case self::OPENROUTER_QWEN_2_5_7B_FREE:
            case self::OPENROUTER_PHI_3_MINI_FREE:
            case self::OPENROUTER_OPENCHAT_3_5_FREE:
                return new EngineEnum(EngineEnum::OPENROUTER);
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Get the driver class for this entity
     */
    public function driverClass(): string
    {
        // Use dynamic model data if available
        if ($this->isDynamic() && isset($this->dynamicModel['driver_class'])) {
            return $this->dynamicModel['driver_class'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
                return GPT4ODriver::class;
            case self::GPT_4O_MINI:
                return GPT4OMiniDriver::class;
            case self::GPT_3_5_TURBO:
                return GPT35TurboDriver::class;
            case self::GPT_5:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
                // GPT-5 models use the same driver as GPT-4O for now
                return GPT4ODriver::class;
            case self::DALL_E_3:
                return DallE3Driver::class;
            case self::DALL_E_2:
                return DallE2Driver::class;
            case self::WHISPER_1:
                return WhisperDriver::class;
            case self::CLAUDE_3_5_SONNET:
                return Claude35SonnetDriver::class;
            case self::CLAUDE_3_HAIKU:
                return Claude3HaikuDriver::class;
            case self::CLAUDE_3_OPUS:
                return Claude3OpusDriver::class;
            case self::GEMINI_1_5_PRO:
                return Gemini15ProDriver::class;
            case self::GEMINI_1_5_FLASH:
                return Gemini15FlashDriver::class;
            case self::SD3_LARGE:
                return SD3LargeDriver::class;
            case self::SDXL_1024:
                return SDXL1024Driver::class;
            case self::ELEVEN_MULTILINGUAL_V2:
                return MultilingualV2Driver::class;
            case self::FAL_FLUX_PRO:
            case self::FLUX_PRO:
                return FluxProDriver::class;
            case self::FAL_LUMA_DREAM:
            case self::KLING_VIDEO:
            case self::LUMA_DREAM_MACHINE:
                return KlingVideoDriver::class;
            case self::DEEPSEEK_CHAT:
                return DeepSeekChatDriver::class;
            case self::DEEPSEEK_REASONER:
                return DeepSeekReasonerDriver::class;
            case self::OPENROUTER_GPT_5:
                return GPT4ODriver::class;
            case self::OPENROUTER_GPT_5_MINI:
                return GPT4OMiniDriver::class;
            case self::OPENROUTER_GPT_5_NANO:
                return GPT4OMiniDriver::class;
            case self::OPENROUTER_GPT_4O:
                return GPT4ODriver::class;
            case self::OPENROUTER_GPT_4O_2024_11_20:
                return GPT4ODriver::class;
            case self::OPENROUTER_GPT_4O_MINI:
                return GPT4OMiniDriver::class;
            case self::OPENROUTER_GPT_4O_MINI_2024_07_18:
                return GPT4OMiniDriver::class;
            case self::OPENROUTER_CLAUDE_4_OPUS:
                return Claude3OpusDriver::class;
            case self::OPENROUTER_CLAUDE_4_SONNET:
                return Claude35SonnetDriver::class;
            case self::OPENROUTER_CLAUDE_3_5_SONNET:
                return Claude35SonnetDriver::class;
            case self::OPENROUTER_CLAUDE_3_5_SONNET_20241022:
                return Claude35SonnetDriver::class;
            case self::OPENROUTER_CLAUDE_3_5_HAIKU:
                return Claude3HaikuDriver::class;
            case self::OPENROUTER_CLAUDE_3_OPUS:
                return Claude3OpusDriver::class;
            case self::OPENROUTER_CLAUDE_3_HAIKU:
                return Claude3HaikuDriver::class;
            case self::OPENROUTER_GEMINI_2_5_PRO:
                return Gemini15ProDriver::class;
            case self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL:
                return Gemini15ProDriver::class;
            case self::OPENROUTER_GEMINI_PRO:
                return Gemini15ProDriver::class;
            case self::OPENROUTER_GEMINI_1_5_PRO:
                return Gemini15ProDriver::class;
            case self::OPENROUTER_GEMINI_2_0_FLASH:
                return Gemini15FlashDriver::class;
            case self::OPENROUTER_LLAMA_3_1_405B:
                return GPT4ODriver::class;
            case self::OPENROUTER_LLAMA_3_1_70B:
                return GPT4ODriver::class;
            case self::OPENROUTER_LLAMA_3_2_90B:
                return GPT4ODriver::class;
            case self::OPENROUTER_LLAMA_3_3_70B:
                return GPT4ODriver::class;
            case self::OPENROUTER_MIXTRAL_8X7B:
                return GPT4ODriver::class;
            case self::OPENROUTER_QWEN_2_5_72B:
                return GPT4ODriver::class;
            case self::OPENROUTER_DEEPSEEK_V3:
                return DeepSeekChatDriver::class;
            case self::OPENROUTER_DEEPSEEK_R1:
                return DeepSeekChatDriver::class;
            case self::OPENROUTER_LLAMA_3_1_8B_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_LLAMA_3_2_3B_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_GEMMA_2_9B_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_MISTRAL_7B_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_QWEN_2_5_7B_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_PHI_3_MINI_FREE:
                return GPT4ODriver::class;
            case self::OPENROUTER_OPENCHAT_3_5_FREE:
                return GPT4ODriver::class;
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Get the display label for this entity
     */
    public function label(): string
    {
        // Use dynamic model name if available
        if ($this->isDynamic() && isset($this->dynamicModel['name'])) {
            return $this->dynamicModel['name'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
                return 'GPT-4o';
            case self::GPT_4O_MINI:
                return 'GPT-4o Mini';
            case self::GPT_3_5_TURBO:
                return 'GPT-3.5 Turbo';
            case self::GPT_5:
                return 'GPT-5';
            case self::GPT_5_MINI:
                return 'GPT-5 Mini';
            case self::GPT_5_NANO:
                return 'GPT-5 Nano';
            case self::DALL_E_3:
                return 'DALL-E 3';
            case self::DALL_E_2:
                return 'DALL-E 2';
            case self::WHISPER_1:
                return 'Whisper';
            case self::CLAUDE_3_5_SONNET:
                return 'Claude 3.5 Sonnet';
            case self::CLAUDE_3_HAIKU:
                return 'Claude 3 Haiku';
            case self::CLAUDE_3_OPUS:
                return 'Claude 3 Opus';
            case self::GEMINI_1_5_PRO:
                return 'Gemini 1.5 Pro';
            case self::GEMINI_1_5_FLASH:
                return 'Gemini 1.5 Flash';
            case self::SD3_LARGE:
                return 'Stable Diffusion 3 Large';
            case self::SD3_MEDIUM:
                return 'Stable Diffusion 3 Medium';
            case self::SDXL_1024:
                return 'Stable Diffusion XL';
            case self::ELEVEN_MULTILINGUAL_V2:
                return 'ElevenLabs Multilingual v2';
            case self::FLUX_PRO:
                return 'Flux Pro';
            case self::KLING_VIDEO:
                return 'Kling Video';
            case self::LUMA_DREAM_MACHINE:
                return 'Luma Dream Machine';
            case self::DEEPSEEK_CHAT:
                return 'DeepSeek Chat';
            case self::DEEPSEEK_REASONER:
                return 'DeepSeek Reasoner';
            case self::OPENROUTER_GPT_5:
                return 'GPT-5 (OpenRouter)';
            case self::OPENROUTER_GPT_5_MINI:
                return 'GPT-5 Mini (OpenRouter)';
            case self::OPENROUTER_GPT_5_NANO:
                return 'GPT-5 Nano (OpenRouter)';
            case self::OPENROUTER_GPT_4O:
                return 'GPT-4o (OpenRouter)';
            case self::OPENROUTER_GPT_4O_2024_11_20:
                return 'GPT-4o (2024-11-20) (OpenRouter)';
            case self::OPENROUTER_GPT_4O_MINI:
                return 'GPT-4o Mini (OpenRouter)';
            case self::OPENROUTER_GPT_4O_MINI_2024_07_18:
                return 'GPT-4o Mini (2024-07-18) (OpenRouter)';
            case self::OPENROUTER_CLAUDE_4_OPUS:
                return 'Claude 4 Opus (OpenRouter)';
            case self::OPENROUTER_CLAUDE_4_SONNET:
                return 'Claude 4 Sonnet (OpenRouter)';
            case self::OPENROUTER_CLAUDE_3_5_SONNET:
                return 'Claude 3.5 Sonnet (OpenRouter)';
            case self::OPENROUTER_CLAUDE_3_5_SONNET_20241022:
                return 'Claude 3.5 Sonnet (2024-10-22) (OpenRouter)';
            case self::OPENROUTER_CLAUDE_3_5_HAIKU:
                return 'Claude 3.5 Haiku (OpenRouter)';
            case self::OPENROUTER_CLAUDE_3_OPUS:
                return 'Claude 3 Opus (OpenRouter)';
            case self::OPENROUTER_CLAUDE_3_HAIKU:
                return 'Claude 3 Haiku (OpenRouter)';
            case self::OPENROUTER_GEMINI_2_5_PRO:
                return 'Gemini 2.5 Pro (OpenRouter)';
            case self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL:
                return 'Gemini 2.5 Pro Experimental (OpenRouter)';
            case self::OPENROUTER_GEMINI_PRO:
                return 'Gemini Pro (OpenRouter)';
            case self::OPENROUTER_GEMINI_1_5_PRO:
                return 'Gemini 1.5 Pro (OpenRouter)';
            case self::OPENROUTER_GEMINI_2_0_FLASH:
                return 'Gemini 2.0 Flash (OpenRouter)';
            case self::OPENROUTER_LLAMA_3_1_405B:
                return 'Llama 3.1 405B (OpenRouter)';
            case self::OPENROUTER_LLAMA_3_1_70B:
                return 'Llama 3.1 70B (OpenRouter)';
            case self::OPENROUTER_LLAMA_3_2_90B:
                return 'Llama 3.2 90B (OpenRouter)';
            case self::OPENROUTER_LLAMA_3_3_70B:
                return 'Llama 3.3 70B (OpenRouter)';
            case self::OPENROUTER_MIXTRAL_8X7B:
                return 'Mixtral 8x7B (OpenRouter)';
            case self::OPENROUTER_QWEN_2_5_72B:
                return 'Qwen 2.5 72B (OpenRouter)';
            case self::OPENROUTER_DEEPSEEK_V3:
                return 'DeepSeek V3 (OpenRouter)';
            case self::OPENROUTER_DEEPSEEK_R1:
                return 'DeepSeek R1 (OpenRouter)';
            case self::OPENROUTER_LLAMA_3_1_8B_FREE:
                return 'Llama 3.1 8B (Free)';
            case self::OPENROUTER_LLAMA_3_2_3B_FREE:
                return 'Llama 3.2 3B (Free)';
            case self::OPENROUTER_GEMMA_2_9B_FREE:
                return 'Gemma 2 9B (Free)';
            case self::OPENROUTER_MISTRAL_7B_FREE:
                return 'Mistral 7B (Free)';
            case self::OPENROUTER_QWEN_2_5_7B_FREE:
                return 'Qwen 2.5 7B (Free)';
            case self::OPENROUTER_PHI_3_MINI_FREE:
                return 'Phi-3 Mini (Free)';
            case self::OPENROUTER_OPENCHAT_3_5_FREE:
                return 'OpenChat 3.5 (Free)';
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Get the credit index (cost multiplier) for this entity
     */
    public function creditIndex(): float
    {
        // Use dynamic model credit index if available
        if ($this->isDynamic() && isset($this->dynamicModel['credit_index'])) {
            return $this->dynamicModel['credit_index'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
                return 2.0;
            case self::GPT_4O_MINI:
                return 0.5;
            case self::GPT_3_5_TURBO:
                return 0.3;
            case self::GPT_5:
                return 3.0;  // GPT-5 is more expensive than GPT-4o
            case self::GPT_5_MINI:
                return 0.7;  // Between GPT-4o-mini and GPT-4o
            case self::GPT_5_NANO:
                return 0.4;  // Similar to GPT-3.5-turbo
            case self::DALL_E_3:
                return 5.0;
            case self::DALL_E_2:
                return 3.0;
            case self::WHISPER_1:
                return 1.0;
            case self::CLAUDE_3_5_SONNET:
                return 1.8;
            case self::CLAUDE_3_HAIKU:
                return 0.8;
            case self::CLAUDE_3_OPUS:
                return 3.0;
            case self::GEMINI_1_5_PRO:
                return 1.5;
            case self::GEMINI_1_5_FLASH:
                return 0.4;
            case self::SD3_LARGE:
                return 4.0;
            case self::SD3_MEDIUM:
                return 3.0;
            case self::SDXL_1024:
                return 2.5;
            case self::ELEVEN_MULTILINGUAL_V2:
                return 2.0;
            case self::FAL_FLUX_PRO:
            case self::FLUX_PRO:
                return 3.5;
            case self::FAL_FLUX_DEV:
                return 2.5;
            case self::FAL_FLUX_SCHNELL:
                return 1.5;
            case self::FAL_SDXL:
                return 2.0;
            case self::FAL_SD3_MEDIUM:
                return 2.5;
            case self::FAL_STABLE_VIDEO:
            case self::FAL_ANIMATEDIFF:
                return 5.0;
            case self::FAL_LUMA_DREAM:
            case self::KLING_VIDEO:
            case self::LUMA_DREAM_MACHINE:
                return 8.0;
            case self::DEEPSEEK_CHAT:
                return 0.2;
            case self::DEEPSEEK_REASONER:
                return 0.4;
            case self::PERPLEXITY_SONAR_LARGE:
                return 1.2;
            case self::PERPLEXITY_SONAR_MEDIUM:
                return 0.8;
            case self::PERPLEXITY_SONAR_SMALL:
                return 0.4;
            case self::SERPER_SEARCH:
                return 0.1;
            case self::SERPER_NEWS:
                return 0.1;
            case self::SERPER_IMAGES:
                return 0.1;
            case self::UNSPLASH_SEARCH:
                return 0.05;
            case self::PLAGIARISM_BASIC:
                return 0.5;
            case self::PLAGIARISM_ADVANCED:
                return 1.0;
            case self::PLAGIARISM_ACADEMIC:
                return 1.5;
            case self::MIDJOURNEY_V6:
                return 4.0;
            case self::MIDJOURNEY_V5:
                return 3.5;
            case self::MIDJOURNEY_NIJI:
                return 3.0;
            case self::AZURE_TTS:
                return 1.0;
            case self::AZURE_STT:
                return 1.0;
            case self::AZURE_TRANSLATOR:
                return 0.3;
            case self::AZURE_TEXT_ANALYTICS:
                return 0.5;
            case self::AZURE_COMPUTER_VISION:
                return 1.5;
            case self::OPENROUTER_GPT_5:
                return 5.0;
            case self::OPENROUTER_GPT_5_MINI:
                return 2.5;
            case self::OPENROUTER_GPT_5_NANO:
                return 1.0;
            case self::OPENROUTER_GPT_4O:
                return 2.2;
            case self::OPENROUTER_GPT_4O_2024_11_20:
                return 2.3;
            case self::OPENROUTER_GPT_4O_MINI:
                return 0.6;
            case self::OPENROUTER_GPT_4O_MINI_2024_07_18:
                return 0.6;
            case self::OPENROUTER_CLAUDE_4_OPUS:
                return 4.5;
            case self::OPENROUTER_CLAUDE_4_SONNET:
                return 3.5;
            case self::OPENROUTER_CLAUDE_3_5_SONNET:
                return 2.0;
            case self::OPENROUTER_CLAUDE_3_5_SONNET_20241022:
                return 2.1;
            case self::OPENROUTER_CLAUDE_3_5_HAIKU:
                return 1.0;
            case self::OPENROUTER_CLAUDE_3_OPUS:
                return 3.2;
            case self::OPENROUTER_CLAUDE_3_HAIKU:
                return 0.9;
            case self::OPENROUTER_GEMINI_2_5_PRO:
                return 3.0;
            case self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL:
                return 3.2;
            case self::OPENROUTER_GEMINI_PRO:
                return 1.7;
            case self::OPENROUTER_GEMINI_1_5_PRO:
                return 1.8;
            case self::OPENROUTER_GEMINI_2_0_FLASH:
                return 1.9;
            case self::OPENROUTER_LLAMA_3_1_405B:
                return 3.0;
            case self::OPENROUTER_LLAMA_3_1_70B:
                return 1.2;
            case self::OPENROUTER_LLAMA_3_2_90B:
                return 1.4;
            case self::OPENROUTER_LLAMA_3_3_70B:
                return 1.3;
            case self::OPENROUTER_MIXTRAL_8X7B:
                return 0.8;
            case self::OPENROUTER_QWEN_2_5_72B:
                return 1.0;
            case self::OPENROUTER_DEEPSEEK_V3:
                return 0.3;
            case self::OPENROUTER_DEEPSEEK_R1:
                return 0.4;
            case self::OPENROUTER_LLAMA_3_1_8B_FREE:
                return 0.0;
            case self::OPENROUTER_LLAMA_3_2_3B_FREE:
                return 0.0;
            case self::OPENROUTER_GEMMA_2_9B_FREE:
                return 0.0;
            case self::OPENROUTER_MISTRAL_7B_FREE:
                return 0.0;
            case self::OPENROUTER_QWEN_2_5_7B_FREE:
                return 0.0;
            case self::OPENROUTER_PHI_3_MINI_FREE:
                return 0.0;
            case self::OPENROUTER_OPENCHAT_3_5_FREE:
                return 0.0;
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
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
        // Use dynamic model content type if available
        if ($this->isDynamic() && isset($this->dynamicModel['content_type'])) {
            return $this->dynamicModel['content_type'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
            case self::GPT_4O_MINI:
            case self::GPT_3_5_TURBO:
            case self::GPT_5:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
            case self::CLAUDE_3_5_SONNET:
            case self::CLAUDE_3_HAIKU:
            case self::CLAUDE_3_OPUS:
            case self::GEMINI_1_5_PRO:
            case self::GEMINI_1_5_FLASH:
            case self::OPENROUTER_MISTRAL_7B_FREE:
            case self::OPENROUTER_QWEN_2_5_7B_FREE:
            case self::OPENROUTER_PHI_3_MINI_FREE:
            case self::OPENROUTER_OPENCHAT_3_5_FREE:
                return 'text';
            case self::FAL_FLUX_DEV:
            case self::FAL_FLUX_SCHNELL:
            case self::FAL_SDXL:
            case self::FAL_SD3_MEDIUM:
                return 'image';
            case self::KLING_VIDEO:
            case self::LUMA_DREAM_MACHINE:
                return 'video';
            case self::WHISPER_1:
            case self::ELEVEN_MULTILINGUAL_V2:
                return 'audio';
            case self::UNSPLASH_SEARCH:
                return 'search';
            case self::PLAGIARISM_BASIC:
            case self::PLAGIARISM_ADVANCED:
            case self::PLAGIARISM_ACADEMIC:
                return 'plagiarism';
            case self::MIDJOURNEY_V6:
            case self::MIDJOURNEY_V5:
            case self::MIDJOURNEY_NIJI:
                return 'image';
            case self::AZURE_TTS:
                return 'audio';
            case self::AZURE_STT:
                return 'audio';
            case self::AZURE_TRANSLATOR:
                return 'text';
            case self::AZURE_TEXT_ANALYTICS:
                return 'text';
            case self::AZURE_COMPUTER_VISION:
                return 'image';
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Get maximum tokens for this model
     */
    public function maxTokens(): int
    {
        // Use dynamic model max tokens if available
        if ($this->isDynamic() && isset($this->dynamicModel['max_tokens'])) {
            return $this->dynamicModel['max_tokens'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
                return 128000;
            case self::GPT_4O_MINI:
                return 128000;
            case self::GPT_3_5_TURBO:
                return 16385;
            case self::GPT_5:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
                return 200000;  // GPT-5 has larger context window
            case self::CLAUDE_3_5_SONNET:
                return 200000;
            case self::CLAUDE_3_HAIKU:
                return 200000;
            case self::CLAUDE_3_OPUS:
                return 200000;
            case self::GEMINI_1_5_PRO:
                return 2097152;
            case self::GEMINI_1_5_FLASH:
                return 1048576;
            case self::DEEPSEEK_CHAT:
                return 32768;
            case self::DEEPSEEK_REASONER:
                return 65536;
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Check if this model supports vision/image input
     */
    public function supportsVision(): bool
    {
        // Use dynamic model vision support if available
        if ($this->isDynamic() && isset($this->dynamicModel['supports_vision'])) {
            return $this->dynamicModel['supports_vision'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
            case self::GPT_5:
            case self::GEMINI_1_5_PRO:
            case self::GEMINI_1_5_FLASH:
                return true;
            case self::GPT_4O_MINI:
            case self::GPT_3_5_TURBO:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
                return false;
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
    }

    /**
     * Check if this model supports streaming
     */
    public function supportsStreaming(): bool
    {
        // Use dynamic model streaming support if available
        if ($this->isDynamic() && isset($this->dynamicModel['supports_streaming'])) {
            return $this->dynamicModel['supports_streaming'];
        }
        
        switch ($this->value) {
            case self::GPT_4O:
            case self::GPT_4O_MINI:
            case self::GPT_3_5_TURBO:
            case self::GPT_5:
            case self::GPT_5_MINI:
            case self::GPT_5_NANO:
            case self::DEEPSEEK_CHAT:
            case self::DEEPSEEK_REASONER:
                return true;
            default:
                throw new \InvalidArgumentException("Unknown model: {$this->value}");
        }
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

    /**
     * Get all available models
     */
    public static function all(): array
    {
        return [
            self::GPT_4O,
            self::GPT_4O_MINI,
            self::GPT_3_5_TURBO,
            self::DALL_E_3,
            self::DALL_E_2,
            self::WHISPER_1,
            self::CLAUDE_3_5_SONNET,
            self::CLAUDE_3_HAIKU,
            self::CLAUDE_3_OPUS,
            self::GEMINI_1_5_PRO,
            self::GEMINI_1_5_FLASH,
            self::SD3_LARGE,
            self::SD3_MEDIUM,
            self::SDXL_1024,
            self::FAL_FLUX_PRO,
            self::FAL_FLUX_DEV,
            self::FAL_FLUX_SCHNELL,
            self::FAL_SDXL,
            self::FAL_SD3_MEDIUM,
            self::FAL_STABLE_VIDEO,
            self::FAL_ANIMATEDIFF,
            self::FAL_LUMA_DREAM,
            self::FLUX_PRO,
            self::KLING_VIDEO,
            self::LUMA_DREAM_MACHINE,
            self::ELEVEN_MULTILINGUAL_V2,
            self::DEEPSEEK_CHAT,
            self::DEEPSEEK_REASONER,
            self::PERPLEXITY_SONAR_LARGE,
            self::PERPLEXITY_SONAR_MEDIUM,
            self::PERPLEXITY_SONAR_SMALL,
            self::SERPER_SEARCH,
            self::SERPER_NEWS,
            self::SERPER_IMAGES,
            self::UNSPLASH_SEARCH,
            self::PLAGIARISM_BASIC,
            self::PLAGIARISM_ADVANCED,
            self::PLAGIARISM_ACADEMIC,
            self::MIDJOURNEY_V6,
            self::MIDJOURNEY_V5,
            self::MIDJOURNEY_NIJI,
            self::AZURE_TTS,
            self::AZURE_STT,
            self::AZURE_TRANSLATOR,
            self::AZURE_TEXT_ANALYTICS,
            self::GOOGLE_TTS,
            self::OPENROUTER_GPT_5,
            self::OPENROUTER_GEMINI_2_5_PRO,
            self::OPENROUTER_CLAUDE_4_OPUS,
            self::OPENROUTER_CLAUDE_4_SONNET,
            self::OPENROUTER_GPT_5_MINI,
            self::OPENROUTER_GEMINI_2_5_PRO_EXPERIMENTAL,
            self::OPENROUTER_LLAMA_3_3_70B,
        ];
    }

    /**
     * Get all available model instances
     */
    public static function cases(): array
    {
        return array_map(fn($value) => new self($value), self::all());
    }

    /**
     * Create model from value
     */
    public static function from(string $value): self
    {
        if (!in_array($value, self::all())) {
            throw new \InvalidArgumentException("Invalid model value: {$value}");
        }
        return new self($value);
    }

}
