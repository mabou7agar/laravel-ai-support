<?php

declare(strict_types=1);

namespace LaravelAIEngine\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

class JsonPayloadSanitizer
{
    public function sanitize(mixed $payload): mixed
    {
        if (is_array($payload)) {
            return array_map(fn (mixed $value): mixed => $this->sanitize($value), $payload);
        }

        if ($payload instanceof JsonSerializable) {
            return $this->sanitize($payload->jsonSerialize());
        }

        if ($payload instanceof Arrayable) {
            return $this->sanitize($payload->toArray());
        }

        if ($payload instanceof Stringable) {
            return $this->sanitizeScalar((string) $payload);
        }

        if (is_object($payload)) {
            return $this->sanitize(get_object_vars($payload));
        }

        if (is_resource($payload)) {
            return null;
        }

        if (is_float($payload) && !is_finite($payload)) {
            return null;
        }

        return $this->sanitizeScalar($payload);
    }

    private function sanitizeScalar(mixed $payload): mixed
    {
        if (!is_string($payload)) {
            return $payload;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encoded)) {
            return $payload;
        }

        $decoded = json_decode($encoded, true);

        return is_string($decoded) ? $decoded : $payload;
    }
}
