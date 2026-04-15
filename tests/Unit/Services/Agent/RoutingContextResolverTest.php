<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\RoutingContextResolver;
use LaravelAIEngine\Services\Agent\SelectedEntityContextService;
use LaravelAIEngine\Tests\UnitTestCase;

class RoutingContextResolverTest extends UnitTestCase
{
    public function test_merge_conversation_context_reuses_selected_entity_and_last_list(): void
    {
        $context = new UnifiedActionContext(
            sessionId: 'session-routing-context',
            userId: 5,
            conversationHistory: [],
            metadata: [
                'selected_entity_context' => [
                    'entity_id' => 42,
                    'entity_type' => 'invoice',
                ],
                'last_entity_list' => [
                    'entity_type' => 'invoice',
                    'entity_ids' => [42, 43],
                ],
            ]
        );

        $resolver = new RoutingContextResolver(new SelectedEntityContextService());
        $options = $resolver->mergeConversationContext($context, [
            'rag_collections' => ['App\\Models\\Invoice'],
        ]);

        $this->assertSame(42, $options['selected_entity']['entity_id']);
        $this->assertSame([42, 43], $options['last_entity_list']['entity_ids']);

        $signals = $resolver->signalsFromContext($context, $options);
        $this->assertSame('invoice', $signals['selected_entity']['entity_type']);
        $this->assertSame('invoice', $signals['last_entity_list']['entity_type']);
        $this->assertSame(['App\\Models\\Invoice'], $signals['rag_collections']);
    }
}
