<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateTtsRequest extends FormRequest
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
            'text' => 'required|string|max:10000',
            'engine' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:200',
            'minutes' => 'nullable|numeric|min:0.1|max:180',
            'voice_id' => 'nullable|string|max:120',
            'use_character' => 'nullable|string|max:120',
            'use_last_character' => 'nullable|boolean',
            'stability' => 'nullable|numeric|min:0|max:1',
            'similarity_boost' => 'nullable|numeric|min:0|max:1',
            'style' => 'nullable|numeric|min:0|max:1',
            'use_speaker_boost' => 'nullable|boolean',
            'parameters' => 'nullable|array',
        ];
    }
}
