<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use LaravelAIEngine\Services\Media\GenerateApiRequestFactory;
use LaravelAIEngine\Tests\UnitTestCase;

class GenerateApiRequestFactoryTest extends UnitTestCase
{
    public function test_it_builds_text_request_with_routing_metadata(): void
    {
        $request = app(GenerateApiRequestFactory::class)->text([
            'prompt' => 'Explain this',
            'engine' => 'openai',
            'model' => 'gpt-4o-mini',
            'preference' => 'speed',
            'system_prompt' => 'Be concise.',
            'max_tokens' => 200,
            'temperature' => 0.2,
            'parameters' => ['format' => 'json'],
        ], '42');

        $this->assertSame('Explain this', $request->getPrompt());
        $this->assertSame('openai', $request->getEngine()->value);
        $this->assertSame('gpt-4o-mini', $request->getModel()->value);
        $this->assertSame(['format' => 'json'], $request->getParameters());
        $this->assertSame('speed', $request->getMetadata()['routing_preference']);
        $this->assertSame('42', $request->getUserId());
    }

    public function test_it_builds_image_request_with_provider_parameters(): void
    {
        $request = app(GenerateApiRequestFactory::class)->image([
            'prompt' => 'Product photo',
            'count' => 2,
            'size' => '1024x1024',
            'source_images' => ['https://example.test/source.png'],
            'parameters' => ['custom' => true],
        ], 'fal_ai', 'fal-ai/nano-banana-2/edit', '77');

        $this->assertSame('Product photo', $request->getPrompt());
        $this->assertSame('fal_ai', $request->getEngine()->value);
        $this->assertSame(2, $request->getParameters()['image_count']);
        $this->assertSame('1024x1024', $request->getParameters()['size']);
        $this->assertSame(['https://example.test/source.png'], $request->getParameters()['source_images']);
    }

    public function test_it_builds_transcription_request_with_file_path(): void
    {
        $request = app(GenerateApiRequestFactory::class)->transcription([
            'engine' => 'openai',
            'model' => 'whisper-1',
            'audio_minutes' => 3.5,
        ], '/tmp/audio.mp3', '77');

        $this->assertSame('Transcribe this audio file.', $request->getPrompt());
        $this->assertSame(['/tmp/audio.mp3'], $request->getFiles());
        $this->assertSame(3.5, $request->getParameters()['audio_minutes']);
    }

    public function test_it_builds_tts_request_with_voice_options(): void
    {
        $request = app(GenerateApiRequestFactory::class)->tts([
            'text' => 'Hello',
            'engine' => 'eleven_labs',
            'model' => 'eleven_multilingual_v2',
            'minutes' => 2,
            'voice_id' => 'voice-1',
            'stability' => 0.5,
        ], ['similarity_boost' => 0.7], '77');

        $this->assertSame('Hello', $request->getPrompt());
        $this->assertSame('voice-1', $request->getParameters()['voice_id']);
        $this->assertSame(0.5, $request->getParameters()['stability']);
        $this->assertSame(0.7, $request->getParameters()['similarity_boost']);
        $this->assertSame(2.0, $request->getParameters()['audio_minutes']);
    }
}
