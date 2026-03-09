<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use PHPUnit\Framework\TestCase;

class SelectedEntityContextServiceTest extends TestCase
{
    public function test_get_from_context_prefers_selected_entity_context(): void
    {
        $service = new SelectedEntityContextService();
        $context = new UnifiedActionContext('session-1', null, metadata: [
            'selected_entity_context' => ['entity_id' => 7, 'entity_type' => 'invoice'],
        ]);

        $selected = $service->getFromContext($context);

        $this->assertSame(7, $selected['entity_id']);
        $this->assertSame('invoice', $selected['entity_type']);
    }

    public function test_bind_to_tool_params_applies_selected_entity_id(): void
    {
        $service = new SelectedEntityContextService();

        $params = $service->bindToToolParams(
            'show_invoice',
            [],
            ['entity_id' => 9, 'entity_data' => ['id' => 9]],
            ['invoice_id' => ['type' => 'integer']]
        );

        $this->assertSame(9, $params['invoice_id']);
        $this->assertSame(['id' => 9], $params['entity_data']);
    }
}
