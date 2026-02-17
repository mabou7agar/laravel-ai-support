<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\FollowUpStateService;
use PHPUnit\Framework\TestCase;

class FollowUpStateServiceTest extends TestCase
{
    public function test_detects_entity_list_context_from_metadata(): void
    {
        $service = new FollowUpStateService();
        $context = new UnifiedActionContext('session-1', 1);
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [1, 2, 3],
            'entity_type' => 'invoice',
        ];

        $this->assertTrue($service->hasEntityListContext($context));
    }

    public function test_formats_entity_context_with_count_and_preview(): void
    {
        $service = new FollowUpStateService();
        $context = new UnifiedActionContext('session-2', 1);
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [4, 5, 6],
            'entity_type' => 'invoice',
        ];

        $formatted = $service->formatEntityListContext($context);

        $this->assertStringContainsString('"count": 3', $formatted);
        $this->assertStringContainsString('"entity_type": "invoice"', $formatted);
    }

    public function test_resolves_model_class_from_selected_entity_context(): void
    {
        $service = new FollowUpStateService();
        $context = new UnifiedActionContext('session-3', 1);
        $context->metadata['selected_entity_context'] = [
            'entity_type' => 'node',
            'model_class' => \LaravelAIEngine\Models\AINode::class,
        ];

        $resolved = $service->resolveModelClass('node', $context);

        $this->assertSame(\LaravelAIEngine\Models\AINode::class, $resolved);
    }

    public function test_formats_selected_entity_context_when_only_selected_entity_exists(): void
    {
        $service = new FollowUpStateService();
        $context = new UnifiedActionContext('session-5', 1);
        $context->metadata['selected_entity_context'] = [
            'entity_id' => 55,
            'entity_type' => 'invoice',
            'model_class' => \LaravelAIEngine\Models\AINode::class,
        ];

        $formatted = $service->formatEntityListContext($context);
        $this->assertStringContainsString('"count": 1', $formatted);
        $this->assertStringContainsString('"selected_entity"', $formatted);
    }
}
