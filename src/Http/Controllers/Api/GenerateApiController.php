<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use LaravelAIEngine\Support\Fal\FalCharacterStore;
use Throwable;

class GenerateApiController extends Controller
{
    public function __construct(
        private readonly AIEngineService $aiEngineService,
        private readonly FalAsyncVideoService $falAsyncVideoService,
        private readonly FalAsyncReferencePackGenerationService $falAsyncReferencePackGenerationService,
        private readonly FalCharacterStore $characterStore
    ) {}

    /**
     * Generate plain text from a prompt.
     *
     * @group AI Generate
     * @bodyParam prompt string required Prompt text.
     * @bodyParam engine string Optional engine slug. Example: openai
     * @bodyParam model string Optional model slug. Example: gpt-4o-mini
     * @bodyParam preference string Optional routing preference when engine/model are omitted. Example: speed
     * @bodyParam system_prompt string Optional system instruction.
     * @bodyParam max_tokens integer Optional max output tokens.
     * @bodyParam temperature number Optional sampling temperature between 0 and 2.
     * @bodyParam parameters object Optional provider-specific parameters.
     */
    public function text(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:10000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'preference' => 'nullable|string|in:cost,cheap,speed,fast,performance,quality',
            'system_prompt' => 'nullable|string|max:4000',
            'max_tokens' => 'nullable|integer|min:1|max:16000',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = array_key_exists('engine', $validated) ? (string) $validated['engine'] : null;
            $model = array_key_exists('model', $validated) ? (string) $validated['model'] : null;
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];
            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['prompt'],
                engine: $engine,
                model: $model,
                parameters: $parameters,
                userId: $this->resolveAuthenticatedUserId(),
                systemPrompt: $validated['system_prompt'] ?? null,
                maxTokens: isset($validated['max_tokens']) ? (int) $validated['max_tokens'] : null,
                temperature: isset($validated['temperature']) ? (float) $validated['temperature'] : null,
                metadata: array_filter([
                    'routing_preference' => $validated['preference'] ?? null,
                ], static fn ($value): bool => $value !== null && $value !== ''),
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Text generation failed.',
                    data: [
                        'engine' => $response->getEngine()->value,
                        'model' => $response->getModel()->value,
                        'usage' => $response->getUsage(),
                        'metadata' => $response->getMetadata(),
                    ],
                    error: ['message' => $response->getError() ?? 'Text generation failed.'],
                    status: 422
                );
            }

            return $this->envelope(
                success: true,
                message: 'Text generated successfully.',
                data: [
                    'content' => $response->getContent(),
                    'engine' => $response->getEngine()->value,
                    'model' => $response->getModel()->value,
                    'usage' => $response->getUsage(),
                    'metadata' => $response->getMetadata(),
                ]
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI generate text failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Text generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Generate image(s) from a prompt.
     *
     * @group AI Generate
     * @bodyParam prompt string required Image prompt.
     * @bodyParam engine string Optional engine slug. Default: openai
     * @bodyParam model string Optional model slug. Default: dall-e-3
     * @bodyParam count integer Optional image count. Default: 1
     * @bodyParam size string Optional provider-specific size (for OpenAI: 1024x1024).
     * @bodyParam quality string Optional provider-specific quality (for OpenAI: standard|hd).
     * @bodyParam frame_count integer Optional Nano Banana keyframe count alias for num_images.
     * @bodyParam mode string Optional Nano Banana mode: generate|edit.
     * @bodyParam source_images array Optional source image URLs for edit workflows.
     * @bodyParam character_sources array Optional package-level character source objects.
     * @bodyParam aspect_ratio string Optional provider-specific aspect ratio.
     * @bodyParam resolution string Optional provider-specific output resolution.
     * @bodyParam seed integer Optional generation seed.
     * @bodyParam thinking_level string Optional Nano Banana thinking level.
     * @bodyParam output_format string Optional output format.
     * @bodyParam parameters object Optional provider-specific parameters.
     */
    public function image(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'count' => 'nullable|integer|min:1|max:8',
            'size' => 'nullable|string|max:50',
            'quality' => 'nullable|string|max:50',
            'frame_count' => 'nullable|integer|min:1|max:4',
            'mode' => 'nullable|string|in:generate,edit',
            'source_images' => 'nullable|array|max:8',
            'source_images.*' => 'nullable|url|max:2048',
            'character_sources' => 'nullable|array|max:4',
            'character_sources.*.name' => 'nullable|string|max:120',
            'character_sources.*.description' => 'nullable|string|max:500',
            'character_sources.*.frontal_image_url' => 'nullable|url|max:2048',
            'character_sources.*.reference_image_urls' => 'nullable|array|max:4',
            'character_sources.*.reference_image_urls.*' => 'nullable|url|max:2048',
            'character_sources.*.metadata' => 'nullable|array',
            'aspect_ratio' => 'nullable|string|max:20',
            'resolution' => 'nullable|string|max:20',
            'seed' => 'nullable|integer',
            'thinking_level' => 'nullable|string|in:minimal,high',
            'output_format' => 'nullable|string|in:jpeg,jpg,png,webp,gif',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? $this->resolveDefaultImageEngine($validated));
            $model = (string) ($validated['model'] ?? $this->resolveDefaultImageModel($validated));
            $count = (int) ($validated['count'] ?? 1);
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];

            if (!empty($validated['size'])) {
                $parameters['size'] = (string) $validated['size'];
            }
            if (!empty($validated['quality'])) {
                $parameters['quality'] = (string) $validated['quality'];
            }
            if (isset($validated['frame_count'])) {
                $parameters['frame_count'] = (int) $validated['frame_count'];
            }
            if (!empty($validated['mode'])) {
                $parameters['mode'] = (string) $validated['mode'];
            }
            if (!empty($validated['source_images'])) {
                $parameters['source_images'] = $validated['source_images'];
            }
            if (!empty($validated['character_sources'])) {
                $parameters['character_sources'] = $validated['character_sources'];
            }
            if (!empty($validated['aspect_ratio'])) {
                $parameters['aspect_ratio'] = (string) $validated['aspect_ratio'];
            }
            if (!empty($validated['resolution'])) {
                $parameters['resolution'] = (string) $validated['resolution'];
            }
            if (isset($validated['seed'])) {
                $parameters['seed'] = (int) $validated['seed'];
            }
            if (!empty($validated['thinking_level'])) {
                $parameters['thinking_level'] = (string) $validated['thinking_level'];
            }
            if (!empty($validated['output_format'])) {
                $parameters['output_format'] = (string) $validated['output_format'];
            }
            if ($model === EntityEnum::FAL_NANO_BANANA_2_EDIT
                && empty($parameters['source_images'])
                && empty($parameters['character_sources'])) {
                return $this->envelope(
                    success: false,
                    message: 'Nano Banana edit requires source_images or character_sources.',
                    error: ['message' => 'Nano Banana edit requires source_images or character_sources.'],
                    status: 422
                );
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['prompt'],
                engine: $engine,
                model: $model,
                parameters: array_merge($parameters, ['image_count' => $count]),
                userId: $this->resolveAuthenticatedUserId(),
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Image generation failed.',
                    data: [
                        'engine' => $engine,
                        'model' => $model,
                        'usage' => $response->getUsage(),
                        'metadata' => $response->getMetadata(),
                    ],
                    error: ['message' => $response->getError() ?? 'Image generation failed.'],
                    status: 422
                );
            }

            return $this->envelope(
                success: true,
                message: 'Image generated successfully.',
                data: [
                    'files' => $response->getFiles(),
                    'content' => $response->getContent(),
                    'engine' => $response->getEngine()->value,
                    'model' => $response->getModel()->value,
                    'usage' => $response->getUsage(),
                    'metadata' => $response->getMetadata(),
                ]
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI generate image failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Image generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Generate video from text, frames, or reference images.
     *
     * @group AI Generate
     * @bodyParam prompt string Optional video prompt. Required for text-only generation.
     * @bodyParam engine string Optional engine slug. Default: fal_ai
     * @bodyParam model string Optional model slug. Auto-selected from inputs when omitted.
     * @bodyParam duration string Optional provider-specific duration.
     * @bodyParam aspect_ratio string Optional video aspect ratio.
     * @bodyParam resolution string Optional video resolution.
     * @bodyParam seed integer Optional generation seed.
     * @bodyParam start_image_url string Optional start frame URL.
     * @bodyParam end_image_url string Optional end frame URL.
     * @bodyParam reference_image_urls array Optional reference image URLs.
     * @bodyParam character_sources array Optional package-level character source objects.
     * @bodyParam generate_audio boolean Optional native audio generation flag.
     * @bodyParam multi_prompt array Optional multi-shot prompt blocks.
     * @bodyParam async boolean Optional submit to FAL queue and return a job id instead of waiting.
     * @bodyParam use_webhook boolean Optional enable provider webhook completion when async=true.
     * @bodyParam parameters object Optional provider-specific parameters.
     */
    public function video(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'nullable|string|max:4000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'duration' => 'nullable',
            'aspect_ratio' => 'nullable|string|max:20',
            'resolution' => 'nullable|string|max:20',
            'seed' => 'nullable|integer',
            'start_image_url' => 'nullable|url|max:2048',
            'end_image_url' => 'nullable|url|max:2048',
            'reference_image_urls' => 'nullable|array|max:8',
            'reference_image_urls.*' => 'nullable|url|max:2048',
            'character_sources' => 'nullable|array|max:4',
            'character_sources.*.name' => 'nullable|string|max:120',
            'character_sources.*.description' => 'nullable|string|max:500',
            'character_sources.*.frontal_image_url' => 'nullable|url|max:2048',
            'character_sources.*.reference_image_urls' => 'nullable|array|max:4',
            'character_sources.*.reference_image_urls.*' => 'nullable|url|max:2048',
            'character_sources.*.metadata' => 'nullable|array',
            'generate_audio' => 'nullable|boolean',
            'multi_prompt' => 'nullable|array|max:8',
            'multi_prompt.*.prompt' => 'nullable|string|max:2000',
            'multi_prompt.*.duration' => 'nullable',
            'async' => 'nullable|boolean',
            'use_webhook' => 'nullable|boolean',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? 'fal_ai');
            $model = (string) ($validated['model'] ?? $this->resolveDefaultVideoModel($validated));
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];
            $prompt = trim((string) ($validated['prompt'] ?? ''));

            if (!empty($validated['duration'])) {
                $parameters['duration'] = $validated['duration'];
            }
            if (!empty($validated['aspect_ratio'])) {
                $parameters['aspect_ratio'] = (string) $validated['aspect_ratio'];
            }
            if (!empty($validated['resolution'])) {
                $parameters['resolution'] = (string) $validated['resolution'];
            }
            if (isset($validated['seed'])) {
                $parameters['seed'] = (int) $validated['seed'];
            }
            if (!empty($validated['start_image_url'])) {
                $parameters['start_image_url'] = (string) $validated['start_image_url'];
                $parameters['image_url'] = (string) $validated['start_image_url'];
            }
            if (!empty($validated['end_image_url'])) {
                $parameters['end_image_url'] = (string) $validated['end_image_url'];
            }
            if (!empty($validated['reference_image_urls'])) {
                $parameters['reference_image_urls'] = $validated['reference_image_urls'];
            }
            if (!empty($validated['character_sources'])) {
                $parameters['character_sources'] = $validated['character_sources'];
            }
            if (array_key_exists('generate_audio', $validated)) {
                $parameters['generate_audio'] = (bool) $validated['generate_audio'];
            }
            if (!empty($validated['multi_prompt'])) {
                $parameters['multi_prompt'] = $validated['multi_prompt'];
            }
            if (array_key_exists('use_webhook', $validated)) {
                $parameters['use_webhook'] = (bool) $validated['use_webhook'];
            }

            if ($validationError = $this->validateResolvedVideoRequest($model, $prompt, $parameters)) {
                return $validationError;
            }

            if ((bool) ($validated['async'] ?? false)) {
                $submitted = $this->falAsyncVideoService->submit(
                    $prompt,
                    array_merge($parameters, ['model' => $model]),
                    $this->resolveAuthenticatedUserId()
                );

                return $this->envelope(
                    success: true,
                    message: 'Video job submitted successfully.',
                    data: [
                        'job_id' => $submitted['job_id'],
                        'status' => $submitted['status']['status'] ?? 'queued',
                        'job' => $submitted['status'],
                        'webhook_url' => $submitted['webhook_url'],
                    ],
                    status: 202
                );
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: $prompt,
                engine: $engine,
                model: $model,
                parameters: $parameters,
                userId: $this->resolveAuthenticatedUserId(),
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Video generation failed.',
                    data: [
                        'engine' => $engine,
                        'model' => $model,
                        'usage' => $response->getUsage(),
                        'metadata' => $response->getMetadata(),
                    ],
                    error: ['message' => $response->getError() ?? 'Video generation failed.'],
                    status: 422
                );
            }

            return $this->envelope(
                success: true,
                message: 'Video generated successfully.',
                data: [
                    'files' => $response->getFiles(),
                    'content' => $response->getContent(),
                    'engine' => $response->getEngine()->value,
                    'model' => $response->getModel()->value,
                    'usage' => $response->getUsage(),
                    'metadata' => $response->getMetadata(),
                ]
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI generate video failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Video generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Fetch local status for an async FAL video job.
     */
    public function videoJobStatus(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'nullable|boolean',
        ]);

        try {
            $status = $this->falAsyncVideoService->getStatus(
                $jobId,
                (bool) ($validated['refresh'] ?? false)
            );

            if ($status === null) {
                return $this->envelope(
                    success: false,
                    message: 'Video job not found.',
                    error: ['message' => 'Video job not found.'],
                    status: 404
                );
            }

            return $this->envelope(
                success: true,
                message: 'Video job status fetched successfully.',
                data: $status
            );
        } catch (Throwable $e) {
            Log::error('AI video job status failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Video job status lookup failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Receive FAL async video webhook callbacks.
     */
    public function falVideoWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'nullable|string|max:120',
            'gateway_request_id' => 'nullable|string|max:120',
            'status' => 'required|string|max:20',
            'error' => 'nullable|string|max:2000',
            'payload' => 'nullable|array',
            'payload_error' => 'nullable|string|max:2000',
        ]);

        $jobId = trim((string) $request->query('job_id', ''));
        $token = trim((string) $request->query('token', ''));

        if ($jobId === '' || $token === '') {
            return $this->envelope(
                success: false,
                message: 'Webhook job token is missing.',
                error: ['message' => 'Webhook job token is missing.'],
                status: 422
            );
        }

        try {
            $status = $this->falAsyncVideoService->handleWebhook($jobId, $token, $validated);

            return $this->envelope(
                success: true,
                message: 'Webhook accepted.',
                data: [
                    'job_id' => $jobId,
                    'status' => $status['status'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('AI video webhook rejected', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Webhook processing failed.',
                error: ['message' => $e->getMessage()],
                status: 422
            );
        }
    }

    /**
     * Submit a preview-only character/reference job backed by the async reference-pack workflow.
     *
     * @group AI Generate
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'name' => 'nullable|string|max:120',
            'save_as' => 'nullable|string|max:120',
            'from_character' => 'nullable|string|max:120',
            'frame_count' => 'nullable|integer|min:1|max:32',
            'look_size' => 'nullable|integer|min:1|max:8',
            'look_id' => 'nullable|string|max:120',
            'look_payload' => 'nullable|array',
            'aspect_ratio' => 'nullable|string|max:20',
            'resolution' => 'nullable|string|max:20',
            'seed' => 'nullable|integer',
            'thinking_level' => 'nullable|string|in:minimal,high',
            'output_format' => 'nullable|string|in:jpeg,jpg,png,webp,gif',
            'voice_id' => 'nullable|string|max:120',
            'voice_settings' => 'nullable|array',
            'voice_settings.stability' => 'nullable|numeric|min:0|max:1',
            'voice_settings.similarity_boost' => 'nullable|numeric|min:0|max:1',
            'voice_settings.style' => 'nullable|numeric|min:0|max:1',
            'voice_settings.use_speaker_boost' => 'nullable|boolean',
            'use_webhook' => 'nullable|boolean',
        ]);

        $validated['preview_only'] = true;
        $validated['entity_type'] = 'character';

        if (!isset($validated['from_reference_pack']) && isset($validated['from_character'])) {
            $validated['from_reference_pack'] = $validated['from_character'];
        }

        return $this->submitReferencePackJob($validated, 'Preview job submitted successfully.');
    }

    /**
     * Submit an async reference-pack workflow.
     *
     * @group AI Generate
     */
    public function referencePack(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|max:4000',
            'entity_type' => 'nullable|string|in:character,object,furniture,vehicle,product,prop,creature',
            'name' => 'nullable|string|max:120',
            'save_as' => 'nullable|string|max:120',
            'from_reference_pack' => 'nullable|string|max:120',
            'from_character' => 'nullable|string|max:120',
            'frame_count' => 'nullable|integer|min:1|max:32',
            'look_size' => 'nullable|integer|min:1|max:8',
            'look_id' => 'nullable|string|max:120',
            'look_payload' => 'nullable|array',
            'aspect_ratio' => 'nullable|string|max:20',
            'resolution' => 'nullable|string|max:20',
            'seed' => 'nullable|integer',
            'thinking_level' => 'nullable|string|in:minimal,high',
            'output_format' => 'nullable|string|in:jpeg,jpg,png,webp,gif',
            'preview_only' => 'nullable|boolean',
            'voice_id' => 'nullable|string|max:120',
            'voice_settings' => 'nullable|array',
            'voice_settings.stability' => 'nullable|numeric|min:0|max:1',
            'voice_settings.similarity_boost' => 'nullable|numeric|min:0|max:1',
            'voice_settings.style' => 'nullable|numeric|min:0|max:1',
            'voice_settings.use_speaker_boost' => 'nullable|boolean',
            'use_webhook' => 'nullable|boolean',
        ]);

        if (!isset($validated['entity_type'])) {
            $validated['entity_type'] = 'character';
        }

        if (!isset($validated['from_reference_pack']) && isset($validated['from_character'])) {
            $validated['from_reference_pack'] = $validated['from_character'];
        }

        return $this->submitReferencePackJob($validated, 'Reference pack job submitted successfully.');
    }

    /**
     * Fetch local status for an async preview/reference-pack job.
     */
    public function referencePackJobStatus(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'nullable|boolean',
        ]);

        try {
            $status = $this->falAsyncReferencePackGenerationService->getStatus(
                $jobId,
                (bool) ($validated['refresh'] ?? false)
            );

            if ($status === null) {
                return $this->envelope(
                    success: false,
                    message: 'Reference pack job not found.',
                    error: ['message' => 'Reference pack job not found.'],
                    status: 404
                );
            }

            return $this->envelope(
                success: true,
                message: 'Reference pack job status fetched successfully.',
                data: $status
            );
        } catch (Throwable $e) {
            Log::error('AI reference pack job status failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Reference pack job status lookup failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Receive FAL async preview/reference-pack webhook callbacks.
     */
    public function falReferencePackWebhook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_id' => 'nullable|string|max:120',
            'gateway_request_id' => 'nullable|string|max:120',
            'status' => 'required|string|max:20',
            'error' => 'nullable|string|max:2000',
            'payload' => 'nullable|array',
            'payload_error' => 'nullable|string|max:2000',
        ]);

        $jobId = trim((string) $request->query('job_id', ''));
        $token = trim((string) $request->query('token', ''));

        if ($jobId === '' || $token === '') {
            return $this->envelope(
                success: false,
                message: 'Webhook job token is missing.',
                error: ['message' => 'Webhook job token is missing.'],
                status: 422
            );
        }

        try {
            $status = $this->falAsyncReferencePackGenerationService->handleWebhook($jobId, $token, $validated);

            return $this->envelope(
                success: true,
                message: 'Webhook accepted.',
                data: [
                    'job_id' => $jobId,
                    'status' => $status['status'] ?? null,
                ]
            );
        } catch (Throwable $e) {
            Log::warning('AI reference pack webhook rejected', ['job_id' => $jobId, 'error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Webhook processing failed.',
                error: ['message' => $e->getMessage()],
                status: 422
            );
        }
    }

    /**
     * Transcribe uploaded audio file to text.
     *
     * @group AI Generate
     * @bodyParam file file required Audio file (wav, mp3, m4a, mp4, webm, ogg).
     * @bodyParam engine string Optional engine slug. Default: openai
     * @bodyParam model string Optional model slug. Default: whisper-1
     * @bodyParam audio_minutes number Optional duration hint for usage accounting.
     * @bodyParam parameters object Optional provider-specific parameters.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:wav,mp3,m4a,mp4,webm,ogg|max:51200',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'audio_minutes' => 'nullable|numeric|min:0.1|max:180',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? 'openai');
            $model = (string) ($validated['model'] ?? 'whisper-1');
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];

            if (isset($validated['audio_minutes'])) {
                $parameters['audio_minutes'] = (float) $validated['audio_minutes'];
            }

            $file = $request->file('file');
            $realPath = $file?->getRealPath();
            if (!is_string($realPath) || trim($realPath) === '') {
                return $this->envelope(
                    success: false,
                    message: 'Uploaded file path could not be resolved.',
                    error: ['message' => 'Invalid uploaded audio file.'],
                    status: 422
                );
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: 'Transcribe this audio file.',
                engine: $engine,
                model: $model,
                parameters: $parameters,
                files: [$realPath],
                userId: $this->resolveAuthenticatedUserId(),
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Audio transcription failed.',
                    data: [
                        'engine' => $engine,
                        'model' => $model,
                        'usage' => $response->getUsage(),
                        'metadata' => $response->getMetadata(),
                    ],
                    error: ['message' => $response->getError() ?? 'Audio transcription failed.'],
                    status: 422
                );
            }

            return $this->envelope(
                success: true,
                message: 'Audio transcribed successfully.',
                data: [
                    'content' => $response->getContent(),
                    'engine' => $response->getEngine()->value,
                    'model' => $response->getModel()->value,
                    'usage' => $response->getUsage(),
                    'metadata' => $response->getMetadata(),
                ]
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI transcribe failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Audio transcription failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Convert text to speech audio.
     *
     * @group AI Generate
     * @bodyParam text string required Text to synthesize.
     * @bodyParam engine string Optional engine slug. Default: eleven_labs
     * @bodyParam model string Optional model slug. Default: eleven_multilingual_v2
     * @bodyParam minutes number Optional duration hint for usage accounting.
     * @bodyParam voice_id string Optional voice id.
     * @bodyParam use_character string Optional saved character alias with stored voice metadata.
     * @bodyParam use_last_character boolean Optional reuse the last saved character voice metadata.
     * @bodyParam stability number Optional voice stability.
     * @bodyParam similarity_boost number Optional voice similarity boost.
     * @bodyParam style number Optional voice style strength.
     * @bodyParam use_speaker_boost boolean Optional speaker boost toggle.
     * @bodyParam parameters object Optional provider-specific parameters.
     */
    public function tts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:10000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'minutes' => 'nullable|numeric|min:0.1|max:180',
            'voice_id' => 'nullable|string|max:120',
            'use_character' => 'nullable|string|max:120',
            'use_last_character' => 'nullable|boolean',
            'stability' => 'nullable|numeric|min:0|max:1',
            'similarity_boost' => 'nullable|numeric|min:0|max:1',
            'style' => 'nullable|numeric|min:0|max:1',
            'use_speaker_boost' => 'nullable|boolean',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? 'eleven_labs');
            $model = (string) ($validated['model'] ?? 'eleven_multilingual_v2');
            $minutes = (float) ($validated['minutes'] ?? 1.0);
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];
            $storedVoice = $this->resolveStoredCharacterVoice($validated);
            if ($storedVoice instanceof JsonResponse) {
                return $storedVoice;
            }

            if ($storedVoice !== []) {
                $parameters = array_merge($storedVoice, $parameters);
            }

            if (!empty($validated['voice_id'])) {
                $parameters['voice_id'] = (string) $validated['voice_id'];
            }
            if (isset($validated['stability'])) {
                $parameters['stability'] = (float) $validated['stability'];
            }
            if (isset($validated['similarity_boost'])) {
                $parameters['similarity_boost'] = (float) $validated['similarity_boost'];
            }
            if (isset($validated['style'])) {
                $parameters['style'] = (float) $validated['style'];
            }
            if (array_key_exists('use_speaker_boost', $validated)) {
                $parameters['use_speaker_boost'] = (bool) $validated['use_speaker_boost'];
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['text'],
                engine: $engine,
                model: $model,
                parameters: array_merge($parameters, ['audio_minutes' => $minutes]),
                userId: $this->resolveAuthenticatedUserId(),
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Text-to-speech generation failed.',
                    data: [
                        'engine' => $engine,
                        'model' => $model,
                        'usage' => $response->getUsage(),
                        'metadata' => $response->getMetadata(),
                    ],
                    error: ['message' => $response->getError() ?? 'Text-to-speech generation failed.'],
                    status: 422
                );
            }

            return $this->envelope(
                success: true,
                message: 'Audio generated successfully.',
                data: [
                    'files' => $response->getFiles(),
                    'content' => $response->getContent(),
                    'engine' => $response->getEngine()->value,
                    'model' => $response->getModel()->value,
                    'usage' => $response->getUsage(),
                    'metadata' => $response->getMetadata(),
                ]
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI tts failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Text-to-speech generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    private function submitReferencePackJob(array $validated, string $successMessage): JsonResponse
    {
        try {
            $prompt = (string) $validated['prompt'];
            unset($validated['prompt']);

            $submitted = $this->falAsyncReferencePackGenerationService->submit(
                $prompt,
                $validated,
                $this->resolveAuthenticatedUserId()
            );

            return $this->envelope(
                success: true,
                message: $successMessage,
                data: [
                    'job_id' => $submitted['job_id'],
                    'status' => $submitted['status']['status'] ?? 'queued',
                    'job' => $submitted['status'],
                    'webhook_url' => $submitted['webhook_url'],
                ],
                status: 202
            );
        } catch (InsufficientCreditsException $e) {
            return $this->envelope(
                success: false,
                message: 'Insufficient credits for this request.',
                error: ['message' => $e->getMessage()],
                status: 402
            );
        } catch (Throwable $e) {
            Log::error('AI reference pack submit failed', ['error' => $e->getMessage()]);

            return $this->envelope(
                success: false,
                message: 'Reference pack submission failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    private function envelope(
        bool $success,
        string $message,
        array $data = [],
        ?array $error = null,
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
            'meta' => [
                'status_code' => $status,
                'schema' => 'ai-engine.v1',
            ],
        ], $status);
    }

    private function generateDirect(AIRequest $request): AIResponse
    {
        return $this->aiEngineService->generateDirect($request);
    }

    private function resolveAuthenticatedUserId(): ?string
    {
        try {
            $id = auth()->id();
            return $id !== null ? (string) $id : null;
        } catch (Throwable $e) {
            // Optional-auth endpoints should degrade to guest context.
            return null;
        }
    }

    private function resolveStoredCharacterVoice(array $validated): array|JsonResponse
    {
        $alias = null;
        if (!empty($validated['use_character'])) {
            $alias = trim((string) $validated['use_character']);
        } elseif (($validated['use_last_character'] ?? false) === true) {
            $lastCharacter = $this->characterStore->getLast();
            if (!is_array($lastCharacter)) {
                return $this->envelope(
                    success: false,
                    message: 'No saved character is available.',
                    error: ['message' => 'No saved character is available.'],
                    status: 422
                );
            }

            $alias = (string) ($lastCharacter['alias'] ?? '');
            if ($alias === '') {
                return $this->envelope(
                    success: false,
                    message: 'Last saved character is missing an alias.',
                    error: ['message' => 'Last saved character is missing an alias.'],
                    status: 422
                );
            }
        }

        if ($alias === null || $alias === '') {
            return [];
        }

        $character = $this->characterStore->get($alias);
        if (!is_array($character)) {
            return $this->envelope(
                success: false,
                message: "Saved character [{$alias}] was not found.",
                error: ['message' => "Saved character [{$alias}] was not found."],
                status: 422
            );
        }

        $voice = $this->extractCharacterVoiceParameters($character);
        if ($voice === []) {
            return $this->envelope(
                success: false,
                message: "Saved character [{$alias}] does not have voice metadata.",
                error: ['message' => "Saved character [{$alias}] does not have voice metadata."],
                status: 422
            );
        }

        return $voice;
    }

    private function extractCharacterVoiceParameters(array $character): array
    {
        $parameters = [];
        if (isset($character['voice_id']) && is_string($character['voice_id']) && trim($character['voice_id']) !== '') {
            $parameters['voice_id'] = trim($character['voice_id']);
        }

        $voiceSettings = is_array($character['voice_settings'] ?? null) ? $character['voice_settings'] : [];
        foreach (['stability', 'similarity_boost', 'style', 'use_speaker_boost'] as $key) {
            if (array_key_exists($key, $voiceSettings)) {
                $parameters[$key] = $voiceSettings[$key];
            }
        }

        return $parameters;
    }

    private function resolveDefaultImageEngine(array $validated): string
    {
        return $this->usesFalImageWorkflow($validated) ? 'fal_ai' : 'openai';
    }

    private function resolveDefaultImageModel(array $validated): string
    {
        if (!$this->usesFalImageWorkflow($validated)) {
            return 'dall-e-3';
        }

        if (($validated['mode'] ?? null) === 'edit'
            || !empty($validated['source_images'])
            || !empty($validated['character_sources'])) {
            return EntityEnum::FAL_NANO_BANANA_2_EDIT;
        }

        return EntityEnum::FAL_NANO_BANANA_2;
    }

    private function resolveDefaultVideoModel(array $validated): string
    {
        if (!empty($validated['character_sources']) || !empty($validated['reference_image_urls'])) {
            return EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO;
        }

        if (!empty($validated['start_image_url']) || !empty($validated['end_image_url'])) {
            return EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO;
        }

        return EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO;
    }

    private function usesFalImageWorkflow(array $validated): bool
    {
        foreach (['frame_count', 'mode', 'source_images', 'character_sources', 'aspect_ratio', 'resolution', 'thinking_level', 'output_format'] as $field) {
            if (array_key_exists($field, $validated)) {
                return true;
            }
        }

        return false;
    }

    private function validateResolvedVideoRequest(string $model, string $prompt, array $parameters): ?JsonResponse
    {
        if ($model === EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO && $prompt === '') {
            return $this->envelope(
                success: false,
                message: 'Prompt is required for text-to-video generation.',
                error: ['message' => 'Prompt is required for text-to-video generation.'],
                status: 422
            );
        }

        if (in_array($model, [
            EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO,
            EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO,
        ], true) && empty($parameters['start_image_url']) && empty($parameters['image_url'])) {
            return $this->envelope(
                success: false,
                message: 'This video model requires start_image_url.',
                error: ['message' => 'This video model requires start_image_url.'],
                status: 422
            );
        }

        if (in_array($model, [
            EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO,
            EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
        ], true) && empty($parameters['reference_image_urls']) && empty($parameters['character_sources'])) {
            return $this->envelope(
                success: false,
                message: 'This video model requires reference_image_urls or character_sources.',
                error: ['message' => 'This video model requires reference_image_urls or character_sources.'],
                status: 422
            );
        }

        return null;
    }
}
