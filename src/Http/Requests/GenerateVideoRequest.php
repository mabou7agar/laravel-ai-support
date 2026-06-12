<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'reference_video_urls' => 'nullable|array|max:3',
            'reference_video_urls.*' => 'nullable|url|max:2048',
            'animation_reference_url' => 'nullable|url|max:2048',
            'animation_reference_urls' => 'nullable|array|max:3',
            'animation_reference_urls.*' => 'nullable|url|max:2048',
            'video_urls' => 'nullable|array|max:3',
            'video_urls.*' => 'nullable|url|max:2048',
            'reference_audio_urls' => 'nullable|array|max:3',
            'reference_audio_urls.*' => 'nullable|url|max:2048',
            'audio_urls' => 'nullable|array|max:3',
            'audio_urls.*' => 'nullable|url|max:2048',
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
            // Provider-specific options (forwarded per-model by the driver whitelist).
            'negative_prompt' => 'nullable|string|max:2500',
            'cfg_scale' => 'nullable|numeric',
            'camera_fixed' => 'nullable|boolean',
            'camera_control' => 'nullable|string|max:60',
            'loop' => 'nullable|boolean',
            'motion_bucket_id' => 'nullable|integer',
            'cond_aug' => 'nullable|numeric',
            'num_frames' => 'nullable|integer',
            'fps' => 'nullable|integer',
            'shot_type' => 'nullable|string|max:30',
            'enable_safety_checker' => 'nullable|boolean',
            'enable_prompt_expansion' => 'nullable|boolean',
            'end_user_id' => 'nullable|string|max:255',
            'parameters' => 'nullable|array',
        ];
    }
}
