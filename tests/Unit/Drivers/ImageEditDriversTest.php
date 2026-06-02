<?php

namespace LaravelAIEngine\Tests\Unit\Drivers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Drivers\OpenAI\OpenAIEngineDriver;
use LaravelAIEngine\Drivers\StableDiffusion\StableDiffusionEngineDriver;
use LaravelAIEngine\DTOs\AIRequest;
use LaravelAIEngine\Enums\EngineEnum;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Tests\TestCase;

class ImageEditDriversTest extends TestCase
{
    private array $history = [];

    private function clientReturning(Response $response): Client
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([$response]));
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    private function pngResponseJson(): Response
    {
        return new Response(200, [], json_encode([
            'created' => 1,
            'data' => [['b64_json' => base64_encode("\x89PNG edited")]],
        ]));
    }

    // ---- OpenAI ----

    public function test_openai_edit_routes_to_images_edits(): void
    {
        $driver = new OpenAIEngineDriver(['api_key' => 'k'], $this->clientReturning($this->pngResponseJson()));

        $response = $driver->editImage(new AIRequest(
            prompt: 'add a hat',
            engine: EngineEnum::OpenAI,
            model: EntityEnum::GPT_IMAGE_1,
            parameters: ['operation' => 'generative_fill', 'image' => "\x89PNG raw", 'mask' => "\x89PNG mask"],
        ));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('generative_fill', $response->getMetadata()['operation']);
        $this->assertStringContainsString('images/edits', (string) $this->history[0]['request']->getUri());
    }

    public function test_openai_variation_routes_to_images_variations(): void
    {
        $driver = new OpenAIEngineDriver(['api_key' => 'k'], $this->clientReturning($this->pngResponseJson()));

        $driver->editImage(new AIRequest(
            prompt: '',
            engine: EngineEnum::OpenAI,
            model: EntityEnum::DALL_E_2,
            parameters: ['operation' => 'variation', 'image' => "\x89PNG raw"],
        ));

        $this->assertStringContainsString('images/variations', (string) $this->history[0]['request']->getUri());
    }

    public function test_openai_edit_requires_an_image(): void
    {
        $driver = new OpenAIEngineDriver(['api_key' => 'k'], $this->clientReturning($this->pngResponseJson()));

        $this->expectException(\InvalidArgumentException::class);
        $driver->editImage(new AIRequest(
            prompt: 'x',
            engine: EngineEnum::OpenAI,
            model: EntityEnum::GPT_IMAGE_1,
            parameters: ['operation' => 'edit'],
        ));
    }

    // ---- Stable Diffusion ----

    private function sdRequest(array $params): AIRequest
    {
        return new AIRequest(
            prompt: (string) ($params['prompt'] ?? 'make it night'),
            engine: EngineEnum::StableDiffusion,
            model: EntityEnum::SDXL_1024,
            parameters: $params,
        );
    }

    private function sdDriver(): StableDiffusionEngineDriver
    {
        $body = json_encode(['artifacts' => [['base64' => base64_encode("\x89PNG sd-edited")]]]);

        return new StableDiffusionEngineDriver(['api_key' => 'k'], $this->clientReturning(new Response(200, [], $body)));
    }

    public function test_sd_image_to_image_routes_to_img2img(): void
    {
        Storage::fake('public');

        $response = $this->sdDriver()->editImage($this->sdRequest([
            'operation' => 'image_to_image',
            'image' => "\x89PNG raw",
            'prompt' => 'make it night',
        ]));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('image_to_image', $response->getMetadata()['operation']);
        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('/image-to-image', $uri);
        $this->assertStringNotContainsString('masking', $uri);
    }

    public function test_sd_inpaint_routes_to_masking_endpoint(): void
    {
        Storage::fake('public');

        $this->sdDriver()->editImage($this->sdRequest([
            'operation' => 'generative_fill',
            'image' => "\x89PNG raw",
            'mask' => "\x89PNG mask",
        ]));

        $this->assertStringContainsString('/image-to-image/masking', (string) $this->history[0]['request']->getUri());
    }

    public function test_capabilities_declare_image_edit(): void
    {
        $openai = new OpenAIEngineDriver(['api_key' => 'k'], $this->clientReturning($this->pngResponseJson()));
        $sd = $this->sdDriver();

        $this->assertTrue($openai->supports('image_edit'));
        $this->assertTrue($sd->supports('image_edit'));
    }
}
