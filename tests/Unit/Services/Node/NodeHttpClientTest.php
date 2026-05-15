<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Node;

use Illuminate\Http\Request;
use LaravelAIEngine\Services\Node\NodeHttpClient;
use LaravelAIEngine\Tests\UnitTestCase;

class NodeHttpClientTest extends UnitTestCase
{
    public function test_extract_forwardable_headers_filters_and_maps_bearer_authorization(): void
    {
        $request = Request::create('/search', 'POST', server: [
            'HTTP_X_REQUEST_ID' => 'request-1',
            'HTTP_X_TRACE_ID' => 'trace-1',
            'HTTP_X_TENANT_ID' => 'tenant-1',
            'HTTP_X_WORKSPACE_ID' => 'workspace-1',
            'HTTP_ACCEPT_LANGUAGE' => 'ar,en;q=0.8',
            'HTTP_AUTHORIZATION' => 'Bearer user-token',
            'HTTP_COOKIE' => 'session=secret',
        ]);

        $headers = NodeHttpClient::extractForwardableHeaders($request);

        $this->assertSame('request-1', $headers['X-Request-Id']);
        $this->assertSame('trace-1', $headers['X-Trace-Id']);
        $this->assertSame('tenant-1', $headers['X-Tenant-Id']);
        $this->assertSame('workspace-1', $headers['X-Workspace-Id']);
        $this->assertSame('ar,en;q=0.8', $headers['Accept-Language']);
        $this->assertSame('Bearer user-token', $headers['X-User-Authorization']);
        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertArrayNotHasKey('Cookie', $headers);
    }

    public function test_extract_forwardable_headers_keeps_non_bearer_authorization(): void
    {
        $request = Request::create('/search', 'POST', server: [
            'HTTP_AUTHORIZATION' => 'Basic encoded-token',
        ]);

        $headers = NodeHttpClient::extractForwardableHeaders($request);

        $this->assertSame('Basic encoded-token', $headers['Authorization']);
        $this->assertArrayNotHasKey('X-User-Authorization', $headers);
    }
}
