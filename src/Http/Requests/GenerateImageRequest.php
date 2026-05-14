<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
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
        ];
    }
}
