<?php

namespace LaravelAIEngine\Tests\Unit\Http\Middleware;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LaravelAIEngine\Http\Middleware\StandardizeApiResponseMiddleware;
use LaravelAIEngine\Tests\UnitTestCase;

class StandardizeApiResponseMiddlewareTest extends UnitTestCase
{
    public function test_it_wraps_success_response_with_standard_envelope(): void
    {
        config()->set('ai-engine.api.standardize_responses', true);

        $middleware = new StandardizeApiResponseMiddleware();
        $request = Request::create('/api/test', 'GET');

        $response = $middleware->handle($request, fn () => new JsonResponse([
            'success' => true,
            'response' => 'ok',
            'actions' => [],
        ], 200));

        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('Request completed.', $data['message']);
        $this->assertArrayNotHasKey('response', $data);
        $this->assertSame('ok', $data['data']['response']);
        $this->assertNull($data['error']);
        $this->assertSame(200, $data['meta']['status_code']);
        $this->assertSame('ai-engine.v1', $data['meta']['schema']);
    }

    public function test_it_normalizes_error_payload(): void
    {
        config()->set('ai-engine.api.standardize_responses', true);

        $middleware = new StandardizeApiResponseMiddleware();
        $request = Request::create('/api/test', 'GET');

        $response = $middleware->handle($request, fn () => new JsonResponse([
            'success' => false,
            'error' => 'Something went wrong',
        ], 500));

        $data = $response->getData(true);

        $this->assertFalse($data['success']);
        $this->assertSame('Something went wrong', $data['message']);
        $this->assertSame('Something went wrong', $data['error']['message']);
        $this->assertSame(500, $data['error']['status_code']);
        $this->assertSame(500, $data['meta']['status_code']);
    }

    public function test_it_skips_non_json_responses(): void
    {
        $middleware = new StandardizeApiResponseMiddleware();
        $request = Request::create('/api/test', 'GET');

        $response = $middleware->handle($request, fn () => new Response('ok', 200));

        $this->assertSame('ok', $response->getContent());
    }

    public function test_it_does_not_double_wrap_already_standardized_payload(): void
    {
        config()->set('ai-engine.api.standardize_responses', true);

        $middleware = new StandardizeApiResponseMiddleware();
        $request = Request::create('/api/test', 'GET');

        $payload = [
            'success' => true,
            'message' => 'Already wrapped',
            'data' => ['value' => 1],
            'error' => null,
            'meta' => ['schema' => 'ai-engine.v1', 'status_code' => 200],
        ];

        $response = $middleware->handle($request, fn () => new JsonResponse($payload, 200));

        $this->assertSame($payload, $response->getData(true));
    }

    public function test_it_uses_translated_default_message_for_current_locale(): void
    {
        config()->set('ai-engine.api.standardize_responses', true);
        app()->setLocale('ar');

        $middleware = new StandardizeApiResponseMiddleware();
        $request = Request::create('/api/test?locale=ar', 'GET');

        $response = $middleware->handle($request, fn () => new JsonResponse([
            'result' => 'ok',
        ], 200));

        $data = $response->getData(true);

        $this->assertTrue($data['success']);
        $this->assertSame('تم تنفيذ الطلب بنجاح.', $data['message']);
    }
}
