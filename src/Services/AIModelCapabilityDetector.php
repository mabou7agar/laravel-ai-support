<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services;

class AIModelCapabilityDetector
{
    public function inferMediaContentType(string $modelId): string
    {
        $model = strtolower($modelId);

        if (str_contains($model, 'video') || str_contains($model, 'veo') || str_contains($model, 'wan')) {
            return 'video';
        }

        if (str_contains($model, 'whisper')
            || str_contains($model, 'tts')
            || str_contains($model, 'audio')
            || str_contains($model, 'lyria')
            || str_contains($model, 'melotts')) {
            return 'audio';
        }

        return 'image';
    }

    public function capabilitiesForMediaType(string $contentType, string $modelId): array
    {
        $model = strtolower($modelId);

        return match ($contentType) {
            'video' => array_values(array_unique(array_filter([
                'inference',
                'video_generation',
                str_contains($model, 'image-to-video') || str_contains($model, 'i2v') ? 'image_to_video' : 'text_to_video',
                str_contains($model, 'image') || str_contains($model, 'i2v') ? 'vision' : null,
            ]))),
            'audio' => array_values(array_unique(array_filter([
                'inference',
                str_contains($model, 'whisper') ? 'transcription' : 'audio_generation',
                str_contains($model, 'whisper') ? 'speech_to_text' : 'text_to_speech',
                str_contains($model, 'tts') || str_contains($model, 'melotts') ? 'tts' : null,
            ]))),
            default => ['inference', 'image_generation', 'text_to_image'],
        };
    }

    public function formatMediaModelName(string $modelId): string
    {
        $name = preg_replace('#^@cf/#', 'Cloudflare ', $modelId);
        $name = str_replace(['/', '-', '_', '.'], ' ', (string) $name);

        return trim(ucwords($name)) ?: $modelId;
    }

    public function detectFalCapabilities(string $modelId, array $metadata): array
    {
        $category = strtolower((string) ($metadata['category'] ?? ''));
        $haystack = strtolower(implode(' ', array_filter([
            $modelId,
            $metadata['display_name'] ?? '',
            $metadata['description'] ?? '',
            $category,
            implode(' ', array_map('strval', (array) ($metadata['tags'] ?? []))),
        ])));

        $capabilities = ['inference'];

        if (str_contains($category, 'text-to-image') || str_contains($haystack, 'text-to-image')) {
            $capabilities[] = 'image_generation';
            $capabilities[] = 'text_to_image';
        }

        if (str_contains($category, 'image-to-image')
            || str_contains($category, 'image-edit')
            || str_contains($haystack, 'edit image')
            || str_contains($haystack, 'image edit')) {
            $capabilities[] = 'image_generation';
            $capabilities[] = 'image_editing';
            $capabilities[] = 'vision';
        }

        if (str_contains($category, 'text-to-video') || str_contains($haystack, 'text-to-video')) {
            $capabilities[] = 'video_generation';
            $capabilities[] = 'text_to_video';
        }

        if (str_contains($category, 'image-to-video') || str_contains($haystack, 'image-to-video')) {
            $capabilities[] = 'video_generation';
            $capabilities[] = 'image_to_video';
            $capabilities[] = 'vision';
        }

        if (str_contains($category, 'reference-to-video') || str_contains($haystack, 'reference-to-video')) {
            $capabilities[] = 'video_generation';
            $capabilities[] = 'reference_to_video';
            $capabilities[] = 'vision';
        }

        if (str_contains($category, 'video') || str_contains($haystack, 'video generation')) {
            $capabilities[] = 'video_generation';
        }

        if (str_contains($category, 'vision')
            || str_contains($haystack, 'visual question')
            || str_contains($haystack, 'captioning')
            || str_contains($haystack, 'object detection')) {
            $capabilities[] = 'vision';
            $capabilities[] = 'image_analysis';
        }

        if (str_contains($category, 'text-to-speech') || str_contains($haystack, 'text-to-speech')) {
            $capabilities[] = 'tts';
            $capabilities[] = 'audio_generation';
        }

        if (str_contains($category, 'speech-to-text') || str_contains($haystack, 'speech-to-text')) {
            $capabilities[] = 'transcription';
            $capabilities[] = 'audio';
        }

        if (str_contains($category, 'audio') || str_contains($haystack, 'music generation')) {
            $capabilities[] = 'audio';
        }

        if (str_contains($category, '3d') || str_contains($haystack, '3d')) {
            $capabilities[] = '3d_generation';
        }

        if (str_contains($category, 'training') || str_contains($haystack, 'fine-tun')) {
            $capabilities[] = 'training';
        }

        if (str_contains($haystack, 'upscale')) {
            $capabilities[] = 'image_upscaling';
            $capabilities[] = 'vision';
        }

        if (str_contains($haystack, 'background removal') || str_contains($haystack, 'remove background') || str_contains($modelId, 'rembg')) {
            $capabilities[] = 'background_removal';
            $capabilities[] = 'vision';
        }

        if (str_contains($haystack, 'streaming') || str_contains($haystack, 'websocket') || str_contains($haystack, 'real-time')) {
            $capabilities[] = 'streaming';
            $capabilities[] = 'realtime';
        }

        return array_values(array_unique($capabilities));
    }

    public function formatFalModelName(string $modelId): string
    {
        $name = str_replace(['fal-ai/', '/', '-'], ['', ' ', ' '], $modelId);

        return trim(ucwords($name)) ?: $modelId;
    }

    public function inferFalVersion(string $modelId): ?string
    {
        if (preg_match('/(?:^|[-\/])v?(\d+(?:\.\d+)*[a-z0-9-]*)/i', $modelId, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function detectOpenRouterCapabilities(array $modelData): array
    {
        $capabilities = ['chat'];

        $name = strtolower($modelData['name'] ?? '');
        $id = strtolower($modelData['id'] ?? '');
        $modality = strtolower((string) ($modelData['architecture']['modality'] ?? ''));
        $supportedParameters = array_values((array) ($modelData['supported_parameters'] ?? []));
        $haystack = trim($name . ' ' . $id . ' ' . $modality . ' ' . strtolower(implode(' ', $supportedParameters)));

        if (str_contains($haystack, 'vision') || str_contains($modality, 'image->text') || str_contains($modality, 'image')) {
            $capabilities[] = 'vision';
        }

        if (str_contains($modality, '->image') || str_contains($haystack, 'image-generation')) {
            $capabilities[] = 'image_generation';
        }

        if (str_contains($haystack, 'audio')) {
            $capabilities[] = 'audio';
        }

        if (str_contains($haystack, 'whisper') || str_contains($haystack, 'transcribe') || str_contains($haystack, 'speech-to-text') || str_contains($haystack, 'stt')) {
            $capabilities[] = 'speech_to_text';
        }

        if (str_contains($haystack, 'tts') || str_contains($haystack, 'text-to-speech')) {
            $capabilities[] = 'text_to_speech';
            $capabilities[] = 'tts';
        }

        if (str_contains($haystack, 'embedding')) {
            $capabilities[] = 'embeddings';
        }

        if (str_contains($name, 'code') || str_contains($id, 'code')) {
            $capabilities[] = 'coding';
        }

        if (str_contains($modality, 'text') || in_array('tools', $supportedParameters, true) || in_array('tool_choice', $supportedParameters, true)) {
            $capabilities[] = 'function_calling';
        }

        return array_values(array_unique($capabilities));
    }

    public function detectCapabilities(string $modelId): array
    {
        if (str_starts_with($modelId, 'gpt-image')) {
            return ['image_generation', 'image_editing', 'vision'];
        }

        $capabilities = ['chat'];

        if (str_contains($modelId, 'vision')
            || str_contains($modelId, 'gpt-4')
            || str_contains($modelId, 'gpt-5')) {
            $capabilities[] = 'vision';
        }

        if (str_contains($modelId, 'o1')
            || str_contains($modelId, 'o3')
            || str_contains($modelId, 'gpt-5')) {
            $capabilities[] = 'reasoning';
        }

        if ((str_contains($modelId, 'gpt-4') || str_contains($modelId, 'gpt-3.5') || str_contains($modelId, 'gpt-5'))
            && !str_starts_with($modelId, 'o1')
            && !str_starts_with($modelId, 'o3')) {
            $capabilities[] = 'function_calling';
            $capabilities[] = 'json_mode';
        }

        if (str_contains($modelId, 'gpt-5') || str_contains($modelId, 'gpt-4o')) {
            $capabilities[] = 'coding';
        }

        return $capabilities;
    }

    public function formatModelName(string $modelId): string
    {
        $name = str_replace('-', ' ', $modelId);
        $name = ucwords($name);
        $name = str_replace('Gpt', 'GPT', $name);
        $name = str_replace('O1', 'O1', $name);

        return $name;
    }
}
