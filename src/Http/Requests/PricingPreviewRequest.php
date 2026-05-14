<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PricingPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'engine' => 'required|string|max:100',
            'model' => 'required|string|max:255',
            'prompt' => 'nullable|string|max:10000',
            'parameters' => 'nullable|array',
        ];
    }
}
