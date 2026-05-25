<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminNodeBulkSyncPayloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payload' => ['nullable', 'string', 'max:500000'],
            'payload_file' => ['nullable', 'file', 'mimes:json,txt', 'max:1024'],
        ];
    }
}
