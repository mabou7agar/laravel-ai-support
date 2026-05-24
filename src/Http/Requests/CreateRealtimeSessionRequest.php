<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateRealtimeSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', 'string', 'in:voice_chat,transcription'],
            'transport' => ['nullable', 'string', 'in:webrtc,websocket,sip,http_stream,descriptor,livekit'],
            'modalities' => ['nullable', 'array'],
            'modalities.*' => ['string', 'max:40'],
            'voice' => ['nullable', 'string', 'max:120'],
            'instructions' => ['nullable', 'string'],
            'tools' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'input_audio_format' => ['nullable', 'string', 'max:80'],
            'output_audio_format' => ['nullable', 'string', 'max:80'],
            'input_audio_transcription' => ['nullable', 'array'],
            'turn_detection' => ['nullable', 'array'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_response_output_tokens' => ['nullable'],
            'provider_options' => ['nullable', 'array'],
            'fallback_pipeline' => ['nullable', 'array'],
            'mint_client_secret' => ['nullable', 'boolean'],
        ];
    }
}
