<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Services\Agent\ResponsePointExtractor;
use LaravelAIEngine\Tests\UnitTestCase;

class ResponsePointExtractorTest extends UnitTestCase
{
    public function test_extracts_bullet_and_numbered_points_without_losing_intro_text(): void
    {
        $result = (new ResponsePointExtractor())->extract(
            "Summary:\n- Create invoice for Acme\n- Add two line items\n1. Confirm tax\n2. Send receipt"
        );

        $this->assertSame('Summary:', $result['text_without_points']);
        $this->assertSame([
            ['text' => 'Create invoice for Acme', 'marker' => '-', 'index' => 1],
            ['text' => 'Add two line items', 'marker' => '-', 'index' => 2],
            ['text' => 'Confirm tax', 'marker' => '1.', 'index' => 3],
            ['text' => 'Send receipt', 'marker' => '2.', 'index' => 4],
        ], $result['points']);
    }
}
