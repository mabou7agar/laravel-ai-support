<?php

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use LaravelAIEngine\DTOs\UploadFileDTO;

class UploadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|max:10240', // 10MB max
            'session_id' => 'required|string|max:255',
            'type' => 'sometimes|string|in:image,document,audio,video',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Please select a file to upload',
            'file.max' => 'File size cannot exceed 10MB',
            'session_id.required' => 'Session ID is required',
            'type.in' => 'Invalid file type',
        ];
    }

    public function toDTO(): UploadFileDTO
    {
        return new UploadFileDTO(
            file: $this->file('file'),
            sessionId: $this->validated('session_id'),
            type: $this->validated('type'),
            userId: auth()->id()
        );
    }
}
