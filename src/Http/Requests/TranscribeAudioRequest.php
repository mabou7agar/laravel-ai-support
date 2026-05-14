<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TranscribeAudioRequest extends FormRequest
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
            'file' => 'required|file|mimes:wav,mp3,m4a,mp4,webm,ogg|max:51200',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'audio_minutes' => 'nullable|numeric|min:0.1|max:180',
            'parameters' => 'nullable|array',
        ];
    }
}
