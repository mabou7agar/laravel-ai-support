<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use Illuminate\Support\Facades\Cache;
use LaravelAIEngine\Services\RAG\AutonomousRAGStateService;
use LaravelAIEngine\Tests\UnitTestCase;

class AutonomousRAGStateServiceTest extends UnitTestCase
{
    public function test_hydrate_options_with_last_entity_list_uses_cached_query_state(): void
    {
        Cache::put('rag_query_state:session-1', [
            'model' => 'invoice',
            'entity_ids' => [10, 11],
            'entity_data' => [['id' => 10], ['id' => 11]],
            'start_position' => 1,
            'end_position' => 2,
            'current_page' => 1,
        ], now()->addMinutes(30));

        $service = new AutonomousRAGStateService();
        $options = $service->hydrateOptionsWithLastEntityList('session-1', []);

        $this->assertSame('invoice', $options['last_entity_list']['entity_type']);
        $this->assertSame([10, 11], $options['last_entity_list']['entity_ids']);
    }

    public function test_resolve_id_filter_value_supports_ordinal_placeholder(): void
    {
        $service = new AutonomousRAGStateService();

        $resolved = $service->resolveIdFilterValue('[use 2nd ID from ENTITY IDS in context]', [
            'last_entity_list' => [
                'entity_ids' => [21, 22, 23],
                'start_position' => 1,
            ],
        ]);

        $this->assertSame(22, $resolved);
    }

    public function test_resolve_selected_entity_prefers_explicit_options(): void
    {
        $service = new AutonomousRAGStateService();

        $selected = $service->resolveSelectedEntity([
            'selected_entity' => [
                'entity_id' => 77,
                'entity_type' => 'invoice',
            ],
            'selected_entity_context' => [
                'entity_id' => 11,
            ],
        ]);

        $this->assertSame(77, $selected['entity_id']);
    }
}
