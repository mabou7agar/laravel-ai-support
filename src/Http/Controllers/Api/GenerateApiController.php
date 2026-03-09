<?php

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\AIEngineService;
use Throwable;

class GenerateApiController extends Controller
{
    public function __construct(
        private readonly AIEngineService $aiEngineService
    ) {}

    /**
     * Generate plain text from a prompt.
     *
     * @group AI Generate
     * @bodyParam prompt string required Prompt text.
     * @bodyParam engine string Optional engine slug. Example: openai
     * @bodyParam model string Optional model slug. Example: gpt-4o-mini
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
            'system_prompt' => 'nullable|string|max:4000',
            'max_tokens' => 'nullable|integer|min:1|max:16000',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? config('ai-engine.default', 'openai'));
            $model = (string) ($validated['model'] ?? config('ai-engine.default_model', 'gpt-4o-mini'));
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];
            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['prompt'],
                engine: $engine,
                model: $model,
                parameters: $parameters,
                systemPrompt: $validated['system_prompt'] ?? null,
                maxTokens: isset($validated['max_tokens']) ? (int) $validated['max_tokens'] : null,
                temperature: isset($validated['temperature']) ? (float) $validated['temperature'] : null,
                userId: auth()->id() ? (string) auth()->id() : null,
            ));

            if (!$response->isSuccess()) {
                return $this->envelope(
                    success: false,
                    message: $response->getError() ?? 'Text generation failed.',
                    data: [
                        'engine' => $engine,
                        'model' => $model,
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
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? 'openai');
            $model = (string) ($validated['model'] ?? 'dall-e-3');
            $count = (int) ($validated['count'] ?? 1);
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];

            if (!empty($validated['size'])) {
                $parameters['size'] = (string) $validated['size'];
            }
            if (!empty($validated['quality'])) {
                $parameters['quality'] = (string) $validated['quality'];
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['prompt'],
                engine: $engine,
                model: $model,
                parameters: array_merge($parameters, ['image_count' => $count]),
                userId: auth()->id() ? (string) auth()->id() : null,
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
                userId: auth()->id() ? (string) auth()->id() : null,
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
     * @bodyParam stability number Optional voice stability.
     * @bodyParam similarity_boost number Optional voice similarity boost.
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
            'stability' => 'nullable|numeric|min:0|max:1',
            'similarity_boost' => 'nullable|numeric|min:0|max:1',
            'parameters' => 'nullable|array',
        ]);

        try {
            $engine = (string) ($validated['engine'] ?? 'eleven_labs');
            $model = (string) ($validated['model'] ?? 'eleven_multilingual_v2');
            $minutes = (float) ($validated['minutes'] ?? 1.0);
            $parameters = is_array($validated['parameters'] ?? null) ? $validated['parameters'] : [];

            if (!empty($validated['voice_id'])) {
                $parameters['voice_id'] = (string) $validated['voice_id'];
            }
            if (isset($validated['stability'])) {
                $parameters['stability'] = (float) $validated['stability'];
            }
            if (isset($validated['similarity_boost'])) {
                $parameters['similarity_boost'] = (float) $validated['similarity_boost'];
            }

            $response = $this->generateDirect(new AIRequest(
                prompt: (string) $validated['text'],
                engine: $engine,
                model: $model,
                parameters: array_merge($parameters, ['audio_minutes' => $minutes]),
                userId: auth()->id() ? (string) auth()->id() : null,
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
}
