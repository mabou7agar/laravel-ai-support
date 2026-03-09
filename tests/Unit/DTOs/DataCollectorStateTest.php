<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\DataCollectorState;
use LaravelAIEngine\Tests\UnitTestCase;

class DataCollectorStateTest extends UnitTestCase
{
    public function test_detected_locale_is_serialized_and_restored(): void
    {
        $state = new DataCollectorState(
            sessionId: 'dc-1',
            configName: 'invoice_create',
            detectedLocale: 'ar'
        );

        $payload = $state->toArray();
        $this->assertSame('ar', $payload['detected_locale']);

        $restored = DataCollectorState::fromArray($payload);
        $this->assertSame('ar', $restored->detectedLocale);
    }
}
