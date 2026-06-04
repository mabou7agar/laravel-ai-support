<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\ProviderTools;

use LaravelAIEngine\Services\ProviderTools\RemoteUrlGuard;
use LaravelAIEngine\Tests\UnitTestCase;

class RemoteUrlGuardTest extends UnitTestCase
{
    public function test_allows_a_public_literal_ip(): void
    {
        // 93.184.216.34 (example.com) — a public address.
        $this->assertTrue(RemoteUrlGuard::isFetchable('https://93.184.216.34/file.png'));
    }

    /**
     * @dataProvider blockedUrls
     */
    public function test_blocks_ssrf_targets(string $url): void
    {
        $this->assertFalse(RemoteUrlGuard::isFetchable($url), "{$url} must be blocked");
    }

    public static function blockedUrls(): array
    {
        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'loopback ip' => ['http://127.0.0.1/'],
            'localhost' => ['http://localhost:6379/'],
            'sub.localhost' => ['http://redis.localhost/'],
            'rfc1918 10/8' => ['http://10.0.0.5/'],
            'rfc1918 192.168' => ['http://192.168.1.1/'],
            'rfc1918 172.16' => ['http://172.16.0.1/'],
            'link-local' => ['http://169.254.10.10/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1/'],
            'not a url' => ['not-a-url'],
            'empty' => [''],
        ];
    }

    public function test_allows_an_unresolvable_host(): void
    {
        // An unresolvable host can't be SSRF'd (the fetch just fails), so it is not
        // blocked here — only hosts that resolve to a private/reserved address are.
        $this->assertTrue(RemoteUrlGuard::isFetchable('http://this-host-does-not-exist.invalid/'));
    }

    public function test_block_private_urls_can_be_disabled(): void
    {
        config()->set('ai-engine.provider_tools.artifacts.block_private_urls', false);

        // With the guard disabled (trusted/offline env), a private IP is allowed —
        // only the scheme check remains.
        $this->assertTrue(RemoteUrlGuard::isFetchable('http://127.0.0.1/'));
        $this->assertFalse(RemoteUrlGuard::isFetchable('file:///etc/passwd'));
    }
}
