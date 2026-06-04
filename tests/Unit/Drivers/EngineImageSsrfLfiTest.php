<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Drivers;

use Illuminate\Support\Facades\Http;
use LaravelAIEngine\Drivers\StableDiffusion\StableDiffusionEngineDriver;
use LaravelAIEngine\Tests\UnitTestCase;

/**
 * Image params (init_image_url, image, ...) are request-controlled. Guards must stop:
 *  - SSRF: a remote init image URL pointing at localhost / link-local / metadata hosts.
 *  - LFI: an arbitrary server filesystem path being read and shipped upstream.
 */
class EngineImageSsrfLfiTest extends UnitTestCase
{
    private function driver(): StableDiffusionEngineDriver
    {
        return new StableDiffusionEngineDriver(['api_key' => 'test-key']);
    }

    private function invoke(object $object, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }

    public function test_remote_init_image_blocks_private_localhost_and_metadata_urls(): void
    {
        config()->set('ai-engine.provider_tools.artifacts.block_private_urls', true);
        Http::fake();

        foreach ([
            'http://127.0.0.1/x.png',
            'http://localhost/x.png',
            'http://169.254.169.254/latest/meta-data/iam/',
        ] as $url) {
            try {
                $this->invoke($this->driver(), 'downloadRemoteImage', [$url]);
                $this->fail("SSRF guard should have blocked {$url}");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('not allowed', $e->getMessage());
            }
        }

        // The guard short-circuits before any HTTP request is made.
        Http::assertNothingSent();
    }

    public function test_local_image_file_read_is_fail_closed_by_default(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'sd_lfi_');
        file_put_contents($tmp, 'secret-bytes');

        // Default: a request-supplied path is NOT read from the server filesystem.
        config()->set('ai-engine.security.allow_local_image_paths', false);
        config()->set('ai-engine.security.image_path_allowlist', []);
        $this->assertFalse($this->invoke($this->driver(), 'localImageFileReadable', [$tmp]));

        // Explicit opt-in re-enables local reads.
        config()->set('ai-engine.security.allow_local_image_paths', true);
        $this->assertTrue($this->invoke($this->driver(), 'localImageFileReadable', [$tmp]));

        // Allowlist scopes reads to specific directories.
        config()->set('ai-engine.security.allow_local_image_paths', false);
        config()->set('ai-engine.security.image_path_allowlist', [dirname($tmp)]);
        $this->assertTrue($this->invoke($this->driver(), 'localImageFileReadable', [$tmp]));

        config()->set('ai-engine.security.image_path_allowlist', ['/nonexistent/other/dir']);
        $this->assertFalse($this->invoke($this->driver(), 'localImageFileReadable', [$tmp]));

        @unlink($tmp);
    }
}
