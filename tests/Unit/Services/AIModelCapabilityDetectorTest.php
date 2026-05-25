<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\AIModelCapabilityDetector;
use LaravelAIEngine\Tests\UnitTestCase;

class AIModelCapabilityDetectorTest extends UnitTestCase
{
    public function test_detects_media_content_types_and_capabilities(): void
    {
        $detector = new AIModelCapabilityDetector();

        $this->assertSame('video', $detector->inferMediaContentType('fal-ai/veo3'));
        $this->assertSame('audio', $detector->inferMediaContentType('openai/whisper-large'));
        $this->assertSame('image', $detector->inferMediaContentType('black-forest-labs/flux'));

        $this->assertContains('image_to_video', $detector->capabilitiesForMediaType('video', 'image-to-video-model'));
        $this->assertContains('speech_to_text', $detector->capabilitiesForMediaType('audio', 'whisper-large'));
    }

    public function test_detects_provider_specific_capabilities(): void
    {
        $detector = new AIModelCapabilityDetector();

        $this->assertContains('image_editing', $detector->detectFalCapabilities('fal-ai/edit', [
            'category' => 'image-edit',
            'description' => 'Edit image with references',
        ]));

        $this->assertContains('function_calling', $detector->detectOpenRouterCapabilities([
            'id' => 'openai/gpt-4o',
            'name' => 'GPT-4o',
            'architecture' => ['modality' => 'text+image->text'],
            'supported_parameters' => ['tools'],
        ]));
    }

    public function test_formats_names_and_versions(): void
    {
        $detector = new AIModelCapabilityDetector();

        $this->assertSame('GPT 4o', $detector->formatModelName('gpt-4o'));
        $this->assertSame('Nano Banana 2', $detector->formatFalModelName('fal-ai/nano-banana-2'));
        $this->assertSame('2', $detector->inferFalVersion('fal-ai/nano-banana-2'));
        $this->assertSame('Cloudflare Black Forest Labs Flux 1 Schnell', $detector->formatMediaModelName('@cf/black-forest-labs/flux-1-schnell'));
    }
}
