<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\StructuredOutput;

use LaravelAIEngine\Services\StructuredOutput\StructuredJsonDecoder;
use PHPUnit\Framework\TestCase;

final class StructuredJsonDecoderTest extends TestCase
{
    public function test_it_decodes_direct_fenced_and_prose_wrapped_json(): void
    {
        $decoder = new StructuredJsonDecoder();

        self::assertSame(['name' => 'Ahmed'], $decoder->decode('{"name":"Ahmed"}'));
        self::assertSame(['name' => 'Ahmed'], $decoder->decode("```json\n{\"name\":\"Ahmed\"}\n```"));
        self::assertSame(
            ['message' => 'brace } inside', 'nested' => ['ok' => true]],
            $decoder->decode('Here is the extraction: {"message":"brace } inside","nested":{"ok":true}} Thanks.'),
        );
    }

    public function test_it_fails_closed_for_invalid_or_unbalanced_content(): void
    {
        $decoder = new StructuredJsonDecoder();

        self::assertSame([], $decoder->decode('no structured value'));
        self::assertSame([], $decoder->decode('prefix {"missing": true'));
    }
}
