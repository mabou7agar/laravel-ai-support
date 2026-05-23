<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent\AiNative;

use LaravelAIEngine\Services\Agent\AiNative\ToolResultAuthorityService;
use LaravelAIEngine\Tests\UnitTestCase;

class ToolResultAuthorityServiceTest extends UnitTestCase
{
    public function test_top_level_bare_id_is_not_authorized_by_unrelated_tool_result(): void
    {
        $service = new ToolResultAuthorityService();

        $arguments = $service->sanitizeArguments([
            'id' => 501,
            'customer_id' => 501,
        ], [
            'tool_results' => [
                [
                    'tool' => 'lookup_customer',
                    'result' => [
                        'success' => true,
                        'data' => ['id' => 501],
                    ],
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('id', $arguments);
        $this->assertSame(501, $arguments['customer_id']);
    }

    public function test_nested_entity_id_is_authorized_only_by_matching_tool_result(): void
    {
        $service = new ToolResultAuthorityService();

        $authorized = $service->sanitizeArguments([
            'customer' => ['id' => 501],
            'product' => ['id' => 501],
        ], [
            'tool_results' => [
                [
                    'tool' => 'lookup_customer',
                    'result' => [
                        'success' => true,
                        'data' => ['id' => 501],
                    ],
                ],
            ],
        ]);

        $this->assertSame(501, $authorized['customer']['id']);
        $this->assertArrayNotHasKey('id', $authorized['product']);
    }
}
