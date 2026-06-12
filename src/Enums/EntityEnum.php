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
use LaravelAIEngine\Drivers\DeepSeek\DeepSeekEngineDriver;
use LaravelAIEngine\Drivers\Ollama\OllamaEngineDriver;
use LaravelAIEngine\Drivers\OpenRouter\OpenRouterEngineDriver;
use LaravelAIEngine\Drivers\LocalAudio\LocalAudioEngineDriver;
use LaravelAIEngine\Services\Models\DynamicModelResolver;
use LaravelAIEngine\Services\Media\VideoModelCatalog;

class EntityEnum
{
    protected static ?DynamicModelResolver $resolver = null;
    /** @var array<string, array|null> memoized database lookups, including misses */
    protected static array $databaseCache = [];
    /** @var array<string, array>|null decoded resources/models.json */
    protected static ?array $manifest = null;
    // OpenAI Models
    public const GPT_4O = 'gpt-4o';
    public const GPT_4O_MINI = 'gpt-4o-mini';
    public const GPT_3_5_TURBO = 'gpt-3.5-turbo';
    public const GPT_5 = 'gpt-5';
    public const GPT_5_MINI = 'gpt-5-mini';
    public const GPT_5_NANO = 'gpt-5-nano';
    public const GPT_IMAGE_1_5 = 'gpt-image-1.5';
    public const GPT_IMAGE_1 = 'gpt-image-1';
    public const GPT_IMAGE_1_MINI = 'gpt-image-1-mini';
    public const DALL_E_3 = 'dall-e-3';
    public const DALL_E_2 = 'dall-e-2';
    public const WHISPER_1 = 'whisper-1';
    public const OPENAI_GPT_4O_TRANSCRIBE = 'gpt-4o-transcribe';
    public const OPENAI_GPT_4O_MINI_TRANSCRIBE = 'gpt-4o-mini-transcribe';
    public const OPENAI_GPT_4O_TRANSCRIBE_DIARIZE = 'gpt-4o-transcribe-diarize';
    public const OPENAI_GPT_4O_MINI_TTS = 'gpt-4o-mini-tts';
    public const OPENAI_TTS_1 = 'tts-1';
    public const OPENAI_TTS_1_HD = 'tts-1-hd';

    // Anthropic Models
    public const CLAUDE_3_5_SONNET = 'claude-3-5-sonnet-20240620';
    public const CLAUDE_3_5_SONNET_20241022 = 'claude-3-5-sonnet-20241022';
    public const CLAUDE_3_7_SONNET = 'claude-3-7-sonnet-20250219';
    public const CLAUDE_SONNET_4_5 = 'claude-sonnet-4-5';
    public const CLAUDE_OPUS_4_5 = 'claude-opus-4-5';
    public const CLAUDE_HAIKU_4_5 = 'claude-haiku-4-5-20251001';
    public const CLAUDE_SONNET_4_6 = 'claude-sonnet-4-6';
    public const CLAUDE_OPUS_4_6 = 'claude-opus-4-6';
    public const CLAUDE_3_HAIKU = 'claude-3-haiku-20240307';
    public const CLAUDE_3_OPUS = 'claude-3-opus-20240229';

    // Gemini Models
    public const GEMINI_1_5_PRO = 'gemini-1.5-pro';
    public const GEMINI_1_5_FLASH = 'gemini-1.5-flash';
    public const GEMINI_2_0_FLASH = 'gemini-2.0-flash';
    public const GEMINI_2_0_FLASH_LITE = 'gemini-2.0-flash-lite';
    public const GEMINI_2_5_PRO = 'gemini-2.5-pro-preview-05-06';
    public const GEMINI_2_5_FLASH = 'gemini-2.5-flash-preview-04-17';
    public const GEMINI_IMAGEN_4_FAST = 'imagen-4.0-fast-generate-001';
    public const GEMINI_IMAGEN_4 = 'imagen-4.0-generate-001';
    public const GEMINI_VEO_3_1 = 'veo-3.1-generate-preview';
    public const GEMINI_VEO_3_1_FAST = 'veo-3.1-fast-generate-preview';
    public const GEMINI_2_5_FLASH_TTS = 'gemini-2.5-flash-preview-tts';
    public const GEMINI_2_5_PRO_TTS = 'gemini-2.5-pro-preview-tts';
    public const GEMINI_3_1_FLASH_TTS_PREVIEW = 'gemini-3.1-flash-tts-preview';
    public const GEMINI_LYRIA_002 = 'lyria-002';

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
    public const FAL_NANO_BANANA_2 = 'fal-ai/nano-banana-2';
    public const FAL_NANO_BANANA_2_EDIT = 'fal-ai/nano-banana-2/edit';
    public const FAL_KLING_O3_IMAGE_TO_VIDEO = 'fal-ai/kling-video/o3/standard/image-to-video';
    public const FAL_KLING_O3_REFERENCE_TO_VIDEO = 'fal-ai/kling-video/o3/standard/reference-to-video';
    public const FAL_SEEDANCE_2_TEXT_TO_VIDEO = 'bytedance/seedance-2.0/text-to-video';
    public const FAL_SEEDANCE_2_IMAGE_TO_VIDEO = 'bytedance/seedance-2.0/image-to-video';
    public const FAL_SEEDANCE_2_REFERENCE_TO_VIDEO = 'bytedance/seedance-2.0/reference-to-video';

    // Seedance 2.0 Fast tier
    public const FAL_SEEDANCE_2_FAST_TEXT_TO_VIDEO = 'bytedance/seedance-2.0/fast/text-to-video';
    public const FAL_SEEDANCE_2_FAST_IMAGE_TO_VIDEO = 'bytedance/seedance-2.0/fast/image-to-video';
    public const FAL_SEEDANCE_2_FAST_REFERENCE_TO_VIDEO = 'bytedance/seedance-2.0/fast/reference-to-video';

    // Seedance v1.5 Pro
    public const FAL_SEEDANCE_15_PRO_TEXT_TO_VIDEO = 'fal-ai/bytedance/seedance/v1.5/pro/text-to-video';
    public const FAL_SEEDANCE_15_PRO_IMAGE_TO_VIDEO = 'fal-ai/bytedance/seedance/v1.5/pro/image-to-video';

    // Seedance v1 Pro / Lite
    public const FAL_SEEDANCE_1_PRO_TEXT_TO_VIDEO = 'fal-ai/bytedance/seedance/v1/pro/text-to-video';
    public const FAL_SEEDANCE_1_PRO_IMAGE_TO_VIDEO = 'fal-ai/bytedance/seedance/v1/pro/image-to-video';
    public const FAL_SEEDANCE_1_LITE_REFERENCE_TO_VIDEO = 'fal-ai/bytedance/seedance/v1/lite/reference-to-video';

    // Kling additional tiers
    public const FAL_KLING_O3_PRO_IMAGE_TO_VIDEO = 'fal-ai/kling-video/o3/pro/image-to-video';
    public const FAL_KLING_V3_PRO_IMAGE_TO_VIDEO = 'fal-ai/kling-video/v3/pro/image-to-video';
    public const FAL_KLING_O1_IMAGE_TO_VIDEO = 'fal-ai/kling-video/o1/image-to-video';
    public const FAL_KLING_V26_PRO_IMAGE_TO_VIDEO = 'fal-ai/kling-video/v2.6/pro/image-to-video';
    public const FAL_KLING_V21_STD_IMAGE_TO_VIDEO = 'fal-ai/kling-video/v2.1/standard/image-to-video';
    public const FAL_KLING_V21_MASTER_TEXT_TO_VIDEO = 'fal-ai/kling-video/v2.1/master/text-to-video';
    public const FAL_KLING_V1_STD_IMAGE_TO_VIDEO = 'fal-ai/kling-video/v1/standard/image-to-video';
    public const FAL_KLING_V1_STD_TEXT_TO_VIDEO = 'fal-ai/kling-video/v1/standard/text-to-video';

    // Luma tiers
    public const FAL_LUMA_DREAM_IMAGE_TO_VIDEO = 'fal-ai/luma-dream-machine/image-to-video';
    public const FAL_LUMA_RAY2_TEXT_TO_VIDEO = 'fal-ai/luma-dream-machine/ray-2';
    public const FAL_LUMA_RAY2_IMAGE_TO_VIDEO = 'fal-ai/luma-dream-machine/ray-2/image-to-video';
    public const FAL_LUMA_RAY2_FLASH_IMAGE_TO_VIDEO = 'fal-ai/luma-dream-machine/ray-2-flash/image-to-video';

    // AnimateDiff text-to-video (standard speed; FAL_ANIMATEDIFF is the turbo tier)
    public const FAL_ANIMATEDIFF_TEXT_TO_VIDEO = 'fal-ai/fast-animatediff/text-to-video';

    // Simplified aliases for common models
    public const FLUX_PRO = 'flux-pro';
    public const KLING_VIDEO = 'kling-video';
    public const LUMA_DREAM_MACHINE = 'luma-dream-machine';

    // ElevenLabs Models
    public const ELEVEN_MULTILINGUAL_V2 = 'eleven_multilingual_v2';
    public const ELEVEN_MULTILINGUAL_STS_V2 = 'eleven_multilingual_sts_v2';
    public const ELEVEN_SCRIBE_V2 = 'scribe_v2';
    public const ELEVEN_MUSIC = 'music_v1';

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

    // Pexels Models
    public const PEXELS_SEARCH = 'pexels-search';

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

    // xAI Grok Models (native xAI engine, OpenAI-compatible API)
    public const GROK_4_1 = 'grok-4.1';
    public const GROK_4 = 'grok-4';
    public const GROK_3_1 = 'grok-3.1';

    // NVIDIA NIM Models (OpenAI-compatible hosted or self-hosted NIM)
    public const NVIDIA_NIM_NEMOTRON_70B = 'nvidia/llama-3.1-nemotron-70b-instruct';
    public const NVIDIA_NIM_LLAMA_3_1_70B = 'meta/llama-3.1-70b-instruct';
    public const NVIDIA_NIM_LLAMA_3_1_8B = 'meta/llama-3.1-8b-instruct';

    // AWS Bedrock Models (Anthropic Claude on Bedrock)
    public const BEDROCK_CLAUDE_SONNET = 'anthropic.claude-3-5-sonnet-20241022-v2:0';
    public const BEDROCK_CLAUDE_HAIKU = 'anthropic.claude-3-haiku-20240307-v1:0';
    public const CLIPDROP_IMAGE_EDIT = 'clipdrop-image-edit';

    // Low-cost and local media provider models
    public const CLOUDFLARE_FLUX_SCHNELL = '@cf/black-forest-labs/flux-1-schnell';
    public const CLOUDFLARE_DREAMSHAPER = '@cf/lykon/dreamshaper-8-lcm';
    public const CLOUDFLARE_WHISPER = '@cf/openai/whisper';
    public const CLOUDFLARE_MELOTTS = '@cf/myshell-ai/melotts';
    public const HUGGINGFACE_FLUX_SCHNELL = 'black-forest-labs/FLUX.1-schnell';
    public const HUGGINGFACE_WHISPER_LARGE_V3 = 'openai/whisper-large-v3';
    public const HUGGINGFACE_MMS_TTS = 'facebook/mms-tts';
    public const REPLICATE_FLUX_SCHNELL = 'black-forest-labs/flux-schnell';
    public const REPLICATE_WAN_IMAGE_TO_VIDEO = 'wavespeedai/wan-2.1-i2v-480p';
    public const REPLICATE_WAN_21_I2V_720P = 'wavespeedai/wan-2.1-i2v-720p';
    public const REPLICATE_WAN_22_I2V_FAST = 'wan-video/wan-2.2-i2v-fast';
    public const REPLICATE_WAN_22_I2V_A14B = 'wan-video/wan-2.2-i2v-a14b';
    public const REPLICATE_WAN_25_I2V = 'wan-video/wan-2.5-i2v';
    public const REPLICATE_WAN_27_I2V = 'wan-video/wan-2.7-i2v';
    public const COMFYUI_DEFAULT_IMAGE = 'comfyui/default-image';
    public const COMFYUI_DEFAULT_VIDEO = 'comfyui/default-video';
    public const LOCAL_WHISPER = 'local-whisper';
    public const LOCAL_TTS = 'local-tts';

    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
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
     * Forget memoized database and manifest lookups (call after the ai_models
     * table changes so already-constructed entities re-resolve).
     */
    public static function flushRuntimeCache(): void
    {
        static::$databaseCache = [];
        static::$manifest = null;
    }

    /**
     * Database-backed metadata for this model, memoized per request.
     * Null when the model is not in the ai_models table or no database is available.
     */
    protected function databaseModel(): ?array
    {
        if (!array_key_exists($this->value, static::$databaseCache)) {
            try {
                static::$databaseCache[$this->value] = $this->getResolver()->resolve($this->value);
            } catch (\Throwable) {
                static::$databaseCache[$this->value] = null;
            }
        }

        return static::$databaseCache[$this->value];
    }

    /**
     * The shipped model catalog (resources/models.json) — offline fallback when
     * a model has not been synced or seeded into the database.
     */
    protected static function manifest(): array
    {
        if (static::$manifest === null) {
            $path = __DIR__ . '/../../resources/models.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            static::$manifest = is_array($decoded) ? $decoded : [];
        }

        return static::$manifest;
    }

    /**
     * Manifest entry for this model, if any.
     */
    protected function manifestModel(): ?array
    {
        return static::manifest()[$this->value] ?? null;
    }

    /**
     * Check if this model ships in the package catalog
     */
    protected function isPredefinedModel(): bool
    {
        return $this->manifestModel() !== null;
    }

    /**
     * Check if this model is loaded dynamically from database
     */
    public function isDynamic(): bool
    {
        return $this->databaseModel() !== null;
    }

    /**
     * Detect engine from model name pattern (for models not in the catalog)
     */
    protected function detectEngineFromModelName(): EngineEnum
    {
        $model = $this->value;

        // Check all engine configs to find the model
        $engines = config('ai-engine.engines', []);
        foreach ($engines as $engineName => $engineConfig) {
            $models = $engineConfig['models'] ?? [];
            if (isset($models[$model])) {
                $engine = EngineEnum::tryFrom($engineName);
                if ($engine !== null) {
                    return $engine;
                }
            }
        }

        // Default to OpenRouter for provider/model format
        if (str_contains($model, '/')) {
            return EngineEnum::OpenRouter;
        }

        // Fallback to OpenAI
        return EngineEnum::OpenAI;
    }

    /**
     * Get the engine this entity belongs to
     */
    public function engine(): EngineEnum
    {
        $db = $this->databaseModel();
        if ($db !== null && !empty($db['engine']) && ($engine = EngineEnum::tryFrom($db['engine'])) !== null) {
            return $engine;
        }

        if ($spec = VideoModelCatalog::get($this->value)) {
            return EngineEnum::from($spec->engine);
        }

        $catalogEngine = $this->manifestModel()['engine'] ?? null;
        if ($catalogEngine !== null && ($engine = EngineEnum::tryFrom($catalogEngine)) !== null) {
            return $engine;
        }

        return $this->detectEngineFromModelName();
    }

    /**
     * Get the driver class for this entity
     */
    public function driverClass(): string
    {
        $db = $this->databaseModel();
        if ($db !== null && !empty($db['driver_class']) && class_exists($db['driver_class'])) {
            return $db['driver_class'];
        }

        if ($spec = VideoModelCatalog::get($this->value)) {
            return $spec->isReplicate()
                ? \LaravelAIEngine\Drivers\Replicate\ReplicateEngineDriver::class
                : \LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver::class;
        }

        $catalogDriver = $this->manifestModel()['driver_class'] ?? null;
        if ($catalogDriver !== null && class_exists($catalogDriver)) {
            return $catalogDriver;
        }

        return $this->detectDriverFromEngine();
    }

    /**
     * Detect driver class from engine for unknown models
     */
    protected function detectDriverFromEngine(): string
    {
        return match ($this->engine()) {
            EngineEnum::OpenAI              => GPT4ODriver::class,
            EngineEnum::Anthropic           => Claude35SonnetDriver::class,
            EngineEnum::Gemini              => Gemini15ProDriver::class,
            EngineEnum::OpenRouter          => OpenRouterEngineDriver::class,
            EngineEnum::DeepSeek            => DeepSeekEngineDriver::class,
            EngineEnum::Ollama              => OllamaEngineDriver::class,
            EngineEnum::FalAI               => \LaravelAIEngine\Drivers\FalAI\FalAIEngineDriver::class,
            EngineEnum::Replicate           => \LaravelAIEngine\Drivers\Replicate\ReplicateEngineDriver::class,
            EngineEnum::HuggingFace         => \LaravelAIEngine\Drivers\HuggingFace\HuggingFaceEngineDriver::class,
            EngineEnum::CloudflareWorkersAI => \LaravelAIEngine\Drivers\CloudflareWorkersAI\CloudflareWorkersAIEngineDriver::class,
            EngineEnum::ComfyUI             => \LaravelAIEngine\Drivers\ComfyUI\ComfyUIEngineDriver::class,
            EngineEnum::ElevenLabs          => MultilingualV2Driver::class,
            EngineEnum::NvidiaNim           => \LaravelAIEngine\Drivers\NvidiaNim\NvidiaNimEngineDriver::class,
            EngineEnum::Bedrock             => \LaravelAIEngine\Drivers\Bedrock\BedrockEngineDriver::class,
            EngineEnum::LocalAudio          => LocalAudioEngineDriver::class,
            default                         => GPT4ODriver::class,
        };
    }

    /**
     * Get the display label for this entity
     */
    public function label(): string
    {
        $db = $this->databaseModel();
        if ($db !== null && !empty($db['name'])) {
            return $db['name'];
        }

        $label = $this->manifestModel()['label'] ?? null;
        if ($label !== null) {
            return $label;
        }

        return ucwords(str_replace(['-', '_', '/'], ' ', $this->value));
    }

    /**
     * Get the credit index (cost multiplier) for this entity
     */
    public function creditIndex(): float
    {
        $db = $this->databaseModel();
        if ($db !== null && isset($db['credit_index'])) {
            return (float) $db['credit_index'];
        }

        if ($spec = VideoModelCatalog::get($this->value)) {
            return $spec->creditIndex;
        }

        $catalogIndex = $this->manifestModel()['credit_index'] ?? null;
        if ($catalogIndex !== null) {
            return (float) $catalogIndex;
        }

        return $this->getCreditIndexFromConfig();
    }

    /**
     * Get credit index from config for unknown models
     */
    protected function getCreditIndexFromConfig(): float
    {
        $engines = config('ai-engine.engines', []);
        foreach ($engines as $engineConfig) {
            $models = $engineConfig['models'] ?? [];
            if (isset($models[$this->value]['credit_index'])) {
                return (float) $models[$this->value]['credit_index'];
            }
        }

        return 1.0; // Default credit index
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
        $db = $this->databaseModel();
        if ($db !== null && !empty($db['content_type'])) {
            return $db['content_type'];
        }

        if (VideoModelCatalog::isVideo($this->value)) {
            return 'video';
        }

        $catalogType = $this->manifestModel()['content_type'] ?? null;
        if ($catalogType !== null) {
            return $catalogType;
        }

        $model = strtolower($this->value);
        if (str_contains($model, 'embedding')) {
            return 'embeddings';
        }

        if (str_contains($model, 'whisper') || str_contains($model, 'transcribe') || str_contains($model, 'speech-to-text') || str_contains($model, 'stt')) {
            return 'audio';
        }

        if (str_contains($model, 'tts') || str_contains($model, 'text-to-speech') || str_contains($model, 'speech')) {
            return 'audio';
        }

        if (str_contains($model, 'image') || str_contains($model, 'imagen')) {
            return 'image';
        }

        return 'text'; // Default content type for unknown models
    }

    /**
     * Get maximum tokens for this model
     */
    public function maxTokens(): int
    {
        $db = $this->databaseModel();
        if ($db !== null && isset($db['max_tokens'])) {
            return (int) $db['max_tokens'];
        }

        $catalogTokens = $this->manifestModel()['max_tokens'] ?? null;
        if ($catalogTokens !== null) {
            return (int) $catalogTokens;
        }

        return 128000; // Default max tokens
    }

    /**
     * Check if this model supports vision/image input
     */
    public function supportsVision(): bool
    {
        $db = $this->databaseModel();
        if ($db !== null && isset($db['supports_vision'])) {
            return (bool) $db['supports_vision'];
        }

        $catalogVision = $this->manifestModel()['supports_vision'] ?? null;
        if ($catalogVision !== null) {
            return (bool) $catalogVision;
        }

        return false; // Default: no vision support
    }

    /**
     * Check if this model supports streaming
     */
    public function supportsStreaming(): bool
    {
        $db = $this->databaseModel();
        if ($db !== null && isset($db['supports_streaming'])) {
            return (bool) $db['supports_streaming'];
        }

        $catalogStreaming = $this->manifestModel()['supports_streaming'] ?? null;
        if ($catalogStreaming !== null) {
            return (bool) $catalogStreaming;
        }

        return true; // Default: assume streaming support
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
     * Try to create engine from value, return null on failure
     */
    public static function tryFrom(string $value): ?self
    {
        try {
            return new self($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Get all entities for a specific engine
     */
    public static function forEngine(EngineEnum $engine): array
    {
        return array_filter(
            self::cases(),
            fn(self $entity) => $entity->engine()->value === $engine->value
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
     * Get all catalog model identifiers
     */
    public static function all(): array
    {
        $manifest = static::manifest();
        if ($manifest !== []) {
            return array_keys($manifest);
        }

        // Manifest missing — fall back to the class constants
        $reflection = new \ReflectionClass(static::class);

        return array_values(array_unique(array_filter($reflection->getConstants(), 'is_string')));
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
     * Accepts any model string - validation is done via config, not enum
     */
    public static function from(string $value): self
    {
        return new self($value);
    }

}
