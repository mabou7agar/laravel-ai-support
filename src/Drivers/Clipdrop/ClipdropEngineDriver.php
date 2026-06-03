<?php

declare(strict_types=1);

namespace LaravelAIEngine\Drivers\Clipdrop;

use GuzzleHttp\Client;
use Illuminate\Support\Str;
use LaravelAIEngine\Drivers\BaseEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Services\AIMediaManager;

/**
 * Clipdrop image-editing provider (clipdrop-api.co). Each operation is a binary
 * image-in / image-out REST call. Dispatched via the unified ImageOperationService
 * but also usable directly through generate()/editImage() for an image-edit model.
 */
class ClipdropEngineDriver extends BaseEngineDriver
{
    public const OP_BACKGROUND_REMOVAL = 'background_removal';
    public const OP_CLEANUP = 'cleanup';
    public const OP_UPSCALE = 'upscale';
    public const OP_SKETCH_TO_IMAGE = 'sketch_to_image';
    public const OP_REIMAGINE = 'reimagine';
    public const OP_REMOVE_TEXT = 'remove_text';

    private Client $httpClient;

    public function __construct(array $config, ?Client $httpClient = null)
    {
        parent::__construct($config);

        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->getTimeout(),
            'base_uri' => $this->getBaseUrl() !== '' ? $this->getBaseUrl() : 'https://clipdrop-api.co',
        ]);
    }

    public function generate(AIRequest $request): AIResponse
    {
        return $this->editImage($request);
    }

    public function stream(AIRequest $request): \Generator
    {
        throw new \InvalidArgumentException('Streaming is not supported by Clipdrop.');
        yield; // unreachable; keeps the return type a Generator
    }

    /**
     * Run an image-editing operation. The operation is read from
     * parameters['operation'] and dispatched to the matching Clipdrop endpoint.
     */
    public function editImage(AIRequest $request): AIResponse
    {
        $params = $request->getParameters();
        $operation = (string) ($params['operation'] ?? self::OP_BACKGROUND_REMOVAL);

        [$endpoint, $multipart] = $this->buildOperation($operation, $request, $params);

        $response = $this->httpClient->post($endpoint, [
            'headers' => ['x-api-key' => $this->getApiKey()],
            'multipart' => $multipart,
        ]);

        $contents = $response->getBody()->getContents();

        if ($contents === '') {
            return AIResponse::error('Clipdrop returned an empty image payload.', $request->getEngine(), $request->getModel());
        }

        $stored = app(AIMediaManager::class)->storeBinary(
            $contents,
            'clipdrop-' . $operation . '-' . Str::uuid() . '.png',
            [
                'engine' => $request->getEngine()->value,
                'ai_model' => $request->getModel()->value,
                'content_type' => 'image',
                'collection_name' => 'edited-images',
                'name' => 'clipdrop-' . $operation,
                'extension' => 'png',
                'mime_type' => 'image/png',
            ]
        );

        $url = $stored['url'] ?? $stored['source_url'] ?? null;

        return AIResponse::success($request->getPrompt(), $request->getEngine(), $request->getModel())
            ->withFiles($url !== null ? [$url] : [])
            ->withMetadata(['operation' => $operation, 'image' => $stored])
            ->withUsage(creditsUsed: $request->getModel()->creditIndex());
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<int, array<string, mixed>>} [endpoint, multipart]
     */
    protected function buildOperation(string $operation, AIRequest $request, array $params): array
    {
        return match ($operation) {
            self::OP_BACKGROUND_REMOVAL => [
                '/remove-background/v1',
                [$this->filePart('image_file', $params['image'] ?? null, 'image')],
            ],
            self::OP_REIMAGINE => [
                '/reimagine/v1/reimagine',
                [$this->filePart('image_file', $params['image'] ?? null, 'image')],
            ],
            self::OP_REMOVE_TEXT => [
                '/remove-text/v1',
                [$this->filePart('image_file', $params['image'] ?? null, 'image')],
            ],
            self::OP_CLEANUP => [
                '/cleanup/v1',
                [
                    $this->filePart('image_file', $params['image'] ?? null, 'image'),
                    $this->filePart('mask_file', $params['mask'] ?? null, 'mask'),
                ],
            ],
            self::OP_UPSCALE => [
                '/image-upscaling/v1/upscale',
                [
                    $this->filePart('image_file', $params['image'] ?? null, 'image'),
                    ['name' => 'target_width', 'contents' => (string) ($params['target_width'] ?? 2048)],
                    ['name' => 'target_height', 'contents' => (string) ($params['target_height'] ?? 2048)],
                ],
            ],
            self::OP_SKETCH_TO_IMAGE => [
                '/sketch-to-image/v1/sketch-to-image',
                [
                    $this->filePart('sketch_file', $params['image'] ?? null, 'sketch'),
                    ['name' => 'prompt', 'contents' => (string) ($params['prompt'] ?? $request->getPrompt())],
                ],
            ],
            default => throw new \InvalidArgumentException("Unsupported Clipdrop operation: {$operation}"),
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function filePart(string $name, mixed $value, string $field): array
    {
        return [
            'name' => $name,
            'contents' => $this->imageContents($value, $field),
            'filename' => $field . '.png',
        ];
    }

    /**
     * Accept a local path, a data: URI, base64, or raw binary image payload.
     */
    protected function imageContents(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Clipdrop operation requires a '{$field}' image.");
        }

        if (str_starts_with($value, 'data:')) {
            $comma = strpos($value, ',');
            $value = $comma !== false ? substr($value, $comma + 1) : $value;
        }

        if (strlen($value) < 1024 && @is_file($value)) {
            return (string) file_get_contents($value);
        }

        $decoded = base64_decode($value, true);
        if ($decoded !== false && base64_encode($decoded) === $value) {
            return $decoded;
        }

        return $value; // already raw binary
    }

    public function getEngine(): EngineEnum
    {
        return EngineEnum::Clipdrop;
    }

    public function getAvailableModels(): array
    {
        return [EntityEnum::CLIPDROP_IMAGE_EDIT];
    }

    public function validateRequest(AIRequest $request): bool
    {
        return true;
    }

    protected function getSupportedCapabilities(): array
    {
        return [
            'image_edit',
            self::OP_BACKGROUND_REMOVAL,
            self::OP_CLEANUP,
            self::OP_UPSCALE,
            self::OP_SKETCH_TO_IMAGE,
            self::OP_REIMAGINE,
            self::OP_REMOVE_TEXT,
        ];
    }

    protected function getEngineEnum(): EngineEnum
    {
        return EngineEnum::Clipdrop;
    }

    protected function getDefaultModel(): EntityEnum
    {
        return EntityEnum::from(EntityEnum::CLIPDROP_IMAGE_EDIT);
    }

    protected function validateConfig(): void
    {
        if ($this->getApiKey() === '') {
            throw new \InvalidArgumentException('Clipdrop API key is not configured (engines.clipdrop.api_key).');
        }
    }
}
