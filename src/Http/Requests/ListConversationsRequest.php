<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListConversationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'limit' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }
}
