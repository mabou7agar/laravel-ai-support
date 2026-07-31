<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\AssistantResourceItem;
use LaravelAIEngine\DTOs\AssistantResponse;
use PHPUnit\Framework\TestCase;

final class AssistantResponseTest extends TestCase
{
    public function test_it_serializes_a_frontend_neutral_response_contract(): void
    {
        $response = new AssistantResponse(
            message: 'Found one course.',
            resources: [new AssistantResourceItem('1', 'course', 'Course')],
            speech: ['text' => 'I found one course.', 'interruptible' => true],
        );

        self::assertSame('completed', $response->toArray()['state']);
        self::assertSame('course', $response->toArray()['resources'][0]['type']);
        self::assertTrue($response->toArray()['speech']['interruptible']);
    }
}
