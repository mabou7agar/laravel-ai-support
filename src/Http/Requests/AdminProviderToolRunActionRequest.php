<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminProviderToolRunActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'run' => 'required|string|max:120',
            'async' => 'nullable|boolean',
            'options' => 'nullable|array',
        ];
    }
}
