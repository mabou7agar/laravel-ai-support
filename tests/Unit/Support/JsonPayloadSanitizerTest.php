<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support;

use JsonSerializable;
use LaravelAIEngine\Support\JsonPayloadSanitizer;
use LaravelAIEngine\Tests\UnitTestCase;

class JsonPayloadSanitizerTest extends UnitTestCase
{
    public function test_it_recursively_sanitizes_values_that_json_cannot_encode(): void
    {
        $stream = fopen('php://memory', 'r');

        try {
            $payload = (new JsonPayloadSanitizer())->sanitize([
                'value' => 'ok',
                'nan' => NAN,
                'resource' => $stream,
                'callback' => static fn (): bool => true,
                'object' => new class implements JsonSerializable {
                    public function jsonSerialize(): array
                    {
                        return ['nested_nan' => INF];
                    }
                },
                'nested' => [
                    'invalid_utf8' => "\xB1\x31",
                ],
            ]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->assertSame('ok', $payload['value']);
        $this->assertNull($payload['nan']);
        $this->assertNull($payload['resource']);
        $this->assertSame([], $payload['callback']);
        $this->assertNull($payload['object']['nested_nan']);
        $this->assertIsString($payload['nested']['invalid_utf8']);
        $this->assertIsString(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
