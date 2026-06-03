<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImageOperationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operation' => 'required|string|in:background_removal,cleanup,object_removal,generative_fill,upscale,sketch_to_image,reimagine,variation,remove_text',
            'image' => 'required|string',
            'mask' => 'sometimes|nullable|string',
            'prompt' => 'sometimes|nullable|string|max:4000',
            'engine' => 'sometimes|nullable|string|max:255',
            'target_width' => 'sometimes|integer|min:1|max:8192',
            'target_height' => 'sometimes|integer|min:1|max:8192',
            'user_id' => 'sometimes|nullable|string|max:255',
        ];
    }
}
