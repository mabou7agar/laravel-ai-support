<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminNodeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $nodeId = (int) $this->input('node_id');

        return [
            'node_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:120', 'alpha_dash', Rule::unique('ai_nodes', 'slug')->ignore($nodeId)],
            'type' => ['required', 'string', 'in:master,child'],
            'url' => ['required', 'url', 'max:2048'],
            'description' => ['nullable', 'string', 'max:1000'],
            'capabilities' => ['nullable', 'string', 'max:2000'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', 'in:active,inactive,error'],
        ];
    }
}
