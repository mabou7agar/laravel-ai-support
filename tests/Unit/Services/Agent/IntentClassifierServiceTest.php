<?php

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\DTOs\UnifiedActionContext;
use LaravelAIEngine\Services\Agent\IntentClassifierService;
use PHPUnit\Framework\TestCase;

class IntentClassifierServiceTest extends TestCase
{
    public function test_classifies_follow_up_question_with_entity_context(): void
    {
        $service = new IntentClassifierService($this->intentConfig());
        $context = new UnifiedActionContext('session-1', 1);

        $signals = $service->classify('what is the total?', $context, true);

        $this->assertTrue($signals['has_entity_list_context']);
        $this->assertTrue($signals['is_follow_up_question']);
        $this->assertFalse($signals['is_explicit_list_request']);
    }

    public function test_classifies_explicit_list_request(): void
    {
        $service = new IntentClassifierService($this->intentConfig());
        $context = new UnifiedActionContext('session-2', 1);

        $signals = $service->classify('list invoices again', $context, true);

        $this->assertTrue($signals['is_explicit_list_request']);
        $this->assertFalse($signals['is_follow_up_question']);
    }

    public function test_extract_position_handles_ordinals_and_numbers(): void
    {
        $service = new IntentClassifierService($this->intentConfig());

        $this->assertSame(2, $service->extractPosition('show second invoice'));
        $this->assertSame(4, $service->extractPosition('number 4 please'));
    }

    public function test_option_selection_uses_last_entity_list_range_when_present(): void
    {
        $service = new IntentClassifierService($this->intentConfig());
        $context = new UnifiedActionContext('session-4', 1);
        $context->metadata['last_entity_list'] = [
            'entity_ids' => [1201, 1202, 1203],
            'entity_type' => 'invoice',
            'start_position' => 11,
            'end_position' => 13,
        ];

        $this->assertTrue($service->isOptionSelection('12', $context));
    }

    protected function intentConfig(): array
    {
        return [
            'list_verbs' => ['list', 'show', 'display', 'search', 'find', 'fetch', 'retrieve', 'refresh', 'relist'],
            'refresh_words' => ['again', 'reload'],
            'record_terms' => ['invoices', 'emails', 'items', 'records'],
            'entity_terms' => ['invoice', 'email', 'item', 'record', 'entry', 'customer', 'product'],
            'followup_keywords' => [
                'what', 'which', 'who', 'when', 'where', 'why', 'how',
                'total', 'sum', 'count', 'average', 'status', 'due',
                'paid', 'unpaid', 'latest', 'earliest',
            ],
            'followup_pronouns' => ['it', 'its', 'them', 'those', 'these', 'that', 'this', 'ones'],
            'ordinal_words' => ['first', 'second', 'third', 'fourth', 'fifth', '1st', '2nd', '3rd', '4th', '5th'],
            'ordinal_map' => [
                'first' => 1,
                'second' => 2,
                'third' => 3,
                'fourth' => 4,
                'fifth' => 5,
                '1st' => 1,
                '2nd' => 2,
                '3rd' => 3,
                '4th' => 4,
                '5th' => 5,
            ],
            'positional_entity_words' => ['item', 'email', 'invoice', 'entry', 'record'],
            'max_positional_index' => 100,
            'max_option_selection' => 10,
        ];
    }
}
