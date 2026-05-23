<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StreamAgentRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'timeout' => 'sometimes|integer|min:1|max:120',
            'poll' => 'sometimes|integer|min:100|max:5000',
            'last_event_id' => 'sometimes|string|max:120',
        ];
    }
}
