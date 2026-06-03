<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use Mockery;
use OpenAI\Contracts\ClientContract;
use LaravelAIEngine\Services\CreditManager;
use LaravelAIEngine\Services\Media\VisionService;
use LaravelAIEngine\Tests\TestCase;

class VisionEncodeImageTest extends TestCase
{
    public function test_encode_image_throws_clear_exception_for_missing_file(): void
    {
        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->never();

        $service = new VisionService($client, app(CreditManager::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image file not found');

        $this->invokeEncodeImage($service, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vision-does-not-exist.png');
    }

    public function test_encode_image_throws_clear_exception_when_file_is_unreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable files as root.');
        }

        $path = $this->temporaryFile('vision-unreadable.png', 'binary-image-bytes');
        chmod($path, 0000);

        try {
            $client = Mockery::mock(ClientContract::class);
            $client->shouldReceive('chat')->never();

            $service = new VisionService($client, app(CreditManager::class));

            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Unable to read image file');

            $this->invokeEncodeImage($service, $path);
        } finally {
            chmod($path, 0644);
            @unlink($path);
        }
    }

    public function test_encode_image_throws_clear_exception_when_mime_cannot_be_detected(): void
    {
        $path = $this->temporaryFile('vision-mime-fail.png', 'binary-image-bytes');

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->never();

        // Subclass that simulates a MIME-detection failure (both finfo and
        // mime_content_type returning false/empty) while leaving file reading intact.
        $service = new class($client, app(CreditManager::class)) extends VisionService {
            protected function detectMimeType(string $filePath): ?string
            {
                return null;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to determine MIME type');

        $this->invokeEncodeImage($service, $path);

        @unlink($path);
    }

    public function test_encode_image_returns_valid_data_url_for_readable_file(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=', true);
        $path = $this->temporaryFile('vision-valid.png', $bytes);

        $client = Mockery::mock(ClientContract::class);
        $client->shouldReceive('chat')->never();

        $service = new VisionService($client, app(CreditManager::class));

        $dataUrl = $this->invokeEncodeImage($service, $path);

        $this->assertStringStartsWith('data:', $dataUrl);
        $this->assertStringContainsString(';base64,', $dataUrl);
        $this->assertStringContainsString(base64_encode($bytes), $dataUrl);

        @unlink($path);
    }

    private function invokeEncodeImage(VisionService $service, string $path): string
    {
        $method = new \ReflectionMethod(VisionService::class, 'encodeImage');
        $method->setAccessible(true);

        return $method->invoke($service, $path);
    }

    private function temporaryFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);

        return $path;
    }
}
