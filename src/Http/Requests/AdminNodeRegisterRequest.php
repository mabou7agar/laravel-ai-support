<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminNodeRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120', 'alpha_dash', Rule::unique('ai_nodes', 'slug')],
            'type' => ['required', 'string', 'in:master,child'],
            'url' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capabilities' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['nullable', 'string', 'in:active,inactive,error'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
