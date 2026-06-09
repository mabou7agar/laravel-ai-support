<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Http\Requests\GenerateImageRequest;
use LaravelAIEngine\Http\Requests\GenerateTextRequest;
use LaravelAIEngine\Http\Requests\GenerateTtsRequest;
use LaravelAIEngine\Http\Requests\GenerateVideoRequest;
use LaravelAIEngine\Http\Requests\GenerateWebsiteRequest;
use LaravelAIEngine\Http\Requests\TranscribeAudioRequest;
use LaravelAIEngine\Services\Media\GenerateAudioService;
use LaravelAIEngine\Services\Media\GenerateImageService;
use LaravelAIEngine\Services\Media\GenerateReferencePackService;
use LaravelAIEngine\Services\Media\GenerateVideoService;
use LaravelAIEngine\Services\Media\GenerateTextService;
use LaravelAIEngine\Services\Media\GenerateWebsiteService;

class GenerateApiController extends Controller
{
    public function __construct(
        private readonly GenerateTextService $text,
        private readonly GenerateImageService $images,
        private readonly GenerateVideoService $videos,
        private readonly GenerateReferencePackService $referencePacks,
        private readonly GenerateAudioService $audio,
        private readonly GenerateWebsiteService $websites
    ) {}

    public function text(GenerateTextRequest $request): JsonResponse
    {
        return $this->text->generate($request->validated());
    }

    public function image(GenerateImageRequest $request): JsonResponse
    {
        return $this->images->generate($request->validated());
    }

    public function video(GenerateVideoRequest $request): JsonResponse
    {
        return $this->videos->generate($request->validated());
    }

    public function website(GenerateWebsiteRequest $request): JsonResponse
    {
        return $this->websites->generate($request->validated());
    }

    public function websiteStream(GenerateWebsiteRequest $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return $this->websites->stream($request->validated());
    }

    public function videoJobStatus(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'nullable|boolean',
        ]);

        return $this->videos->status($jobId, (bool) ($validated['refresh'] ?? false));
    }

    public function falVideoWebhook(Request $request): JsonResponse
    {
        return $this->videos->webhook(
            trim((string) $request->query('job_id', '')),
            trim((string) $request->query('token', '')),
            $this->validateWebhookPayload($request)
        );
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate($this->previewRules());
        $validated['preview_only'] = true;
        $validated['entity_type'] = 'character';

        if (!isset($validated['from_reference_pack']) && isset($validated['from_character'])) {
            $validated['from_reference_pack'] = $validated['from_character'];
        }

        return $this->referencePacks->submit($validated, 'Preview job submitted successfully.');
    }

    public function referencePack(Request $request): JsonResponse
    {
        $validated = $request->validate($this->referencePackRules());
        $validated['entity_type'] ??= 'character';

        if (!isset($validated['from_reference_pack']) && isset($validated['from_character'])) {
            $validated['from_reference_pack'] = $validated['from_character'];
        }

        return $this->referencePacks->submit($validated, 'Reference pack job submitted successfully.');
    }

    public function referencePackJobStatus(Request $request, string $jobId): JsonResponse
    {
        $validated = $request->validate([
            'refresh' => 'nullable|boolean',
        ]);

        return $this->referencePacks->status($jobId, (bool) ($validated['refresh'] ?? false));
    }

    public function falReferencePackWebhook(Request $request): JsonResponse
    {
        return $this->referencePacks->webhook(
            trim((string) $request->query('job_id', '')),
            trim((string) $request->query('token', '')),
            $this->validateWebhookPayload($request)
        );
    }

    public function transcribe(TranscribeAudioRequest $request): JsonResponse
    {
        return $this->audio->transcribe($request->validated(), $request->file('file'));
    }

    public function tts(GenerateTtsRequest $request): JsonResponse
    {
        return $this->audio->tts($request->validated());
    }

    private function validateWebhookPayload(Request $request): array
    {
        return $request->validate([
            'request_id' => 'nullable|string|max:120',
            'gateway_request_id' => 'nullable|string|max:120',
            'status' => 'required|string|max:20',
            'error' => 'nullable|string|max:2000',
            'payload' => 'nullable|array',
            'payload_error' => 'nullable|string|max:2000',
        ]);
    }

    private function previewRules(): array
    {
        return array_merge($this->referencePackSharedRules(), [
            'prompt' => 'required|string|max:4000',
            'from_character' => 'nullable|string|max:120',
        ]);
    }

    private function referencePackRules(): array
    {
        return array_merge($this->referencePackSharedRules(), [
            'prompt' => 'required|string|max:4000',
            'entity_type' => 'nullable|string|in:character,object,furniture,vehicle,product,prop,creature',
            'from_reference_pack' => 'nullable|string|max:120',
            'from_character' => 'nullable|string|max:120',
            'preview_only' => 'nullable|boolean',
        ]);
    }

    private function referencePackSharedRules(): array
    {
        return [
            'name' => 'nullable|string|max:120',
            'save_as' => 'nullable|string|max:120',
            'frame_count' => 'nullable|integer|min:1|max:32',
            'look_size' => 'nullable|integer|min:1|max:8',
            'look_id' => 'nullable|string|max:120',
            'look_payload' => 'nullable|array',
            'selected_looks' => 'nullable|array',
            'selected_looks.*' => 'array',
            'look_mode' => 'nullable|string|in:strict_selected_set,strict_stored,guided,vendor',
            'strict_stored_looks' => 'nullable|boolean',
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
        ];
    }
}
