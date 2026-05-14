<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Http\Requests;

use Illuminate\Support\Facades\Validator;
use LaravelAIEngine\Http\Requests\GenerateImageRequest;
use LaravelAIEngine\Http\Requests\GenerateTextRequest;
use LaravelAIEngine\Http\Requests\GenerateTtsRequest;
use LaravelAIEngine\Http\Requests\TranscribeAudioRequest;
use LaravelAIEngine\Tests\UnitTestCase;

class GenerateApiRequestsTest extends UnitTestCase
{
    public function test_generate_text_request_accepts_valid_payload(): void
    {
        $validator = Validator::make([
            'prompt' => 'Summarize this.',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'preference' => 'speed',
            'parameters' => ['response_format' => ['type' => 'json_object']],
        ], (new GenerateTextRequest())->rules());

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    public function test_generate_image_request_rejects_invalid_source_image_url(): void
    {
        $validator = Validator::make([
            'prompt' => 'Product photo',
            'source_images' => ['not-a-url'],
        ], (new GenerateImageRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('source_images.0', $validator->errors()->messages());
    }

    public function test_transcribe_audio_request_reuses_audio_validation_rules(): void
    {
        $rules = (new TranscribeAudioRequest())->rules();

        $this->assertSame('required|file|mimes:wav,mp3,m4a,mp4,webm,ogg|max:51200', $rules['file']);
        $this->assertSame('nullable|numeric|min:0.1|max:180', $rules['audio_minutes']);
    }

    public function test_generate_tts_request_limits_voice_parameters(): void
    {
        $validator = Validator::make([
            'text' => 'Hello',
            'stability' => 2,
        ], (new GenerateTtsRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('stability', $validator->errors()->messages());
    }
}
