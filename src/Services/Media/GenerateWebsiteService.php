<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LaravelAIEngine\DTOs\WebsiteGenerationRequest;
use LaravelAIEngine\Exceptions\InsufficientCreditsException;
use LaravelAIEngine\Services\Design\WebsiteBuilderService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * HTTP adapter for the website builder: turns validated request input into a
 * standard ai-engine API envelope.
 */
class GenerateWebsiteService
{
    public function __construct(
        private readonly WebsiteBuilderService $builder,
        private readonly GenerateApiResponseFactory $responses,
        private readonly GenerateApiUserResolver $users,
    ) {}

    public function generate(array $validated): JsonResponse
    {
        if (!(bool) config('ai-engine.design.enabled', true)) {
            return $this->responses->envelope(
                success: false,
                message: 'Website generation is disabled.',
                error: ['message' => 'The design website builder is disabled in configuration.'],
                status: 403
            );
        }

        try {
            $request = WebsiteGenerationRequest::fromArray($validated, $this->users->id());
            $result = $this->builder->build($request);

            return $this->responses->envelope(
                success: true,
                message: 'Website generated successfully.',
                data: $result->toArray()
            );
        } catch (InsufficientCreditsException $e) {
            return $this->responses->insufficientCredits($e);
        } catch (InvalidArgumentException $e) {
            return $this->responses->envelope(
                success: false,
                message: $e->getMessage(),
                error: ['message' => $e->getMessage()],
                status: 422
            );
        } catch (Throwable $e) {
            Log::error('AI generate website failed', ['error' => $e->getMessage()]);

            return $this->responses->envelope(
                success: false,
                message: 'Website generation failed.',
                error: ['message' => $e->getMessage()],
                status: 500
            );
        }
    }

    /**
     * Server-Sent Events stream: emits design_system → content deltas →
     * quality_review → done (or an error event).
     */
    public function stream(array $validated): StreamedResponse
    {
        $request = WebsiteGenerationRequest::fromArray($validated, $this->users->id());

        $response = new StreamedResponse(function () use ($request): void {
            $emit = static function (string $event, array $data): void {
                echo 'event: ' . $event . "\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            if (!(bool) config('ai-engine.design.enabled', true)) {
                $emit('error', ['message' => 'Website generation is disabled.']);

                return;
            }

            try {
                foreach ($this->builder->stream($request) as $message) {
                    $emit($message['event'], $message['data']);
                }
            } catch (InsufficientCreditsException $e) {
                $emit('error', ['message' => $e->getMessage(), 'code' => 402]);
            } catch (Throwable $e) {
                Log::error('AI stream website failed', ['error' => $e->getMessage()]);
                $emit('error', ['message' => $e->getMessage()]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);

        return $response;
    }
}
