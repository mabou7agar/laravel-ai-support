<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\DTOs\AIResponse;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Services\Drivers\DriverRegistry;

/**
 * Provider-agnostic image-editing pipeline. Maps an operation (background
 * removal, cleanup/object removal, upscale, sketch-to-image, reimagine,
 * generative fill, variation, remove text) to a capable engine driver and
 * dispatches it through the driver's editImage() entry point.
 *
 * Any driver that implements editImage(AIRequest) and declares the operation (or
 * the generic 'image_edit') capability can serve operations — so OpenAI/Stable
 * Diffusion edit+variation slot in beside Clipdrop without changing this service.
 */
class ImageOperationService
{
    /** Operation => default engine when the caller does not pick one. */
    protected const DEFAULT_ENGINE = [
        'background_removal' => EngineEnum::CLIPDROP,
        'cleanup'            => EngineEnum::CLIPDROP,
        'object_removal'     => EngineEnum::CLIPDROP,
        'upscale'            => EngineEnum::CLIPDROP,
        'sketch_to_image'    => EngineEnum::CLIPDROP,
        'reimagine'          => EngineEnum::CLIPDROP,
        'remove_text'        => EngineEnum::CLIPDROP,
    ];

    /** Aliases normalized to a driver-understood operation name. */
    protected const ALIASES = [
        'object_removal'   => 'cleanup',
        'generative_fill'  => 'cleanup',
        'variation'        => 'reimagine',
    ];

    public function __construct(
        protected DriverRegistry $drivers,
    ) {}

    /**
     * @return array<int, string> supported operation names
     */
    public function operations(): array
    {
        return array_keys(self::DEFAULT_ENGINE);
    }

    /**
     * @param array<string, mixed> $params operation inputs (image, mask, prompt, target_width, ...)
     */
    public function apply(string $operation, array $params = [], ?string $engine = null, ?string $userId = null): AIResponse
    {
        $requested = $operation;
        $engineName = $engine ?? (self::DEFAULT_ENGINE[$operation] ?? null);

        if ($engineName === null) {
            throw new \InvalidArgumentException("Unknown image operation: {$requested}");
        }

        $driverOperation = self::ALIASES[$operation] ?? $operation;

        $driver = $this->drivers->resolve($engineName);

        if (!method_exists($driver, 'editImage')) {
            throw new \RuntimeException("Engine '{$engineName}' does not support image editing.");
        }

        if (!$driver->supports($driverOperation) && !$driver->supports('image_edit')) {
            throw new \RuntimeException("Engine '{$engineName}' does not support the '{$requested}' operation.");
        }

        $engineEnum = EngineEnum::from($engineName);
        $model = $engineEnum->getDefaultModels()[0] ?? null;

        $request = new AIRequest(
            prompt: (string) ($params['prompt'] ?? ''),
            engine: $engineEnum,
            model: $model,
            parameters: array_merge($params, ['operation' => $driverOperation, 'requested_operation' => $requested]),
            userId: $userId,
        );

        return $driver->editImage($request);
    }
}
