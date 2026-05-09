<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services;

use LaravelAIEngine\Services\Actions\ActionFlowGuideService;
use LaravelAIEngine\Tests\TestCase;

class ActionFlowGuideServiceTest extends TestCase
{
    public function test_sales_flow_uses_generic_tool_names_and_relation_steps(): void
    {
        $guide = (new ActionFlowGuideService())->guide('create_sales_invoice', [
            'label' => 'Create invoice',
            'required' => ['customer_name', 'items'],
            'parameters' => ['customer_name' => ['type' => 'string']],
        ]);

        $this->assertTrue($guide['success']);
        $this->assertContains('review_customer_relation', collect($guide['flow'])->pluck('step')->all());
        $this->assertSame('update_action_draft', $guide['flow'][0]['tool']);
        $this->assertSame('execute_action', $guide['flow'][6]['tool']);
        $this->assertContains(
            'Never set execute_action.confirmed=true unless the user explicitly confirms the final prepared draft.',
            $guide['guardrails']
        );
    }
}
