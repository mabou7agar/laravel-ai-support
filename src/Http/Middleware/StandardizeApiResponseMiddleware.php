<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StandardizeApiResponseMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!config('ai-engine.api.standardize_responses', true)) {
            return $response;
        }

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $decoded = $response->getData(true);
        if (!is_array($decoded)) {
            $decoded = ['value' => $decoded];
        }

        if ($this->alreadyStandardized($decoded)) {
            return $response;
        }

        $status = $response->getStatusCode();
        $success = array_key_exists('success', $decoded)
            ? (bool) $decoded['success']
            : ($status >= 200 && $status < 400);

        $message = $this->resolveMessage($decoded, $success);
        $error = $success ? null : $this->normalizeError($decoded['error'] ?? null, $message, $status);
        $meta = $this->resolveMeta($decoded, $status);
        $data = $this->resolveData($decoded);

        $standard = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'error' => $error,
            'meta' => $meta,
        ];

        $response->setData($standard);

        return $response;
    }

    protected function alreadyStandardized(array $payload): bool
    {
        return array_key_exists('success', $payload)
            && array_key_exists('message', $payload)
            && array_key_exists('data', $payload)
            && array_key_exists('error', $payload)
            && array_key_exists('meta', $payload);
    }

    protected function resolveData(array $payload): mixed
    {
        $known = ['success', 'message', 'error', 'meta'];
        $data = array_diff_key($payload, array_flip($known));

        if (array_keys($data) === ['data']) {
            return $data['data'];
        }

        return $this->isAssoc($data) ? $data : array_values($data);
    }

    protected function resolveMessage(array $payload, bool $success): string
    {
        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        if (!$success && is_string($payload['error'] ?? null) && trim((string) $payload['error']) !== '') {
            return (string) $payload['error'];
        }

        return $success
            ? $this->translate('ai-engine::messages.api.request_completed', 'Request completed.')
            : $this->translate('ai-engine::messages.api.request_failed', 'Request failed.');
    }

    protected function normalizeError(mixed $error, string $fallbackMessage, int $status): ?array
    {
        if (is_array($error)) {
            return array_merge(['status_code' => $status], $error);
        }

        if (is_string($error) && trim($error) !== '') {
            return [
                'message' => $error,
                'status_code' => $status,
            ];
        }

        return [
            'message' => $fallbackMessage,
            'status_code' => $status,
        ];
    }

    protected function resolveMeta(array $payload, int $status): array
    {
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['status_code'] = $meta['status_code'] ?? $status;
        $meta['schema'] = $meta['schema'] ?? 'ai-engine.v1';

        return $meta;
    }

    protected function isAssoc(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    protected function translate(string $key, string $fallback): string
    {
        $translated = __($key);

        if (!is_string($translated) || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }
}
