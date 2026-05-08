<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Agent;

use LaravelAIEngine\Contracts\AgentCapabilityProvider;
use LaravelAIEngine\DTOs\AgentCapabilityDocument;
use LaravelAIEngine\Services\Agent\AgentCapabilityRegistry;
use LaravelAIEngine\Tests\UnitTestCase;
use RuntimeException;

class AgentCapabilityRegistryTest extends UnitTestCase
{
    public function test_it_resolves_configured_capability_providers_and_deduplicates_documents(): void
    {
        config()->set('ai-agent.capability_providers', [
            'business' => TestBusinessCapabilityProvider::class,
        ]);

        $documents = app(AgentCapabilityRegistry::class)->documents();

        $this->assertCount(2, $documents);
        $this->assertSame('business_action:create_invoice', $documents[0]->id);
        $this->assertSame('Create invoice replacement from second provider row.', $documents[0]->text);
        $this->assertSame('business_action:query_invoices', $documents[1]->id);
        $this->assertSame('query', $documents[1]->payload['operation']);
    }

    public function test_it_filters_providers_by_name_or_class(): void
    {
        config()->set('ai-agent.capability_providers', [
            'business' => TestBusinessCapabilityProvider::class,
        ]);

        $byName = app(AgentCapabilityRegistry::class)->documents(['business']);
        $byClass = app(AgentCapabilityRegistry::class)->documents([TestBusinessCapabilityProvider::class]);
        $missing = app(AgentCapabilityRegistry::class)->documents(['missing']);

        $this->assertCount(2, $byName);
        $this->assertCount(2, $byClass);
        $this->assertSame([], $missing);
    }

    public function test_it_rejects_configured_classes_that_are_not_capability_providers(): void
    {
        config()->set('ai-agent.capability_providers', [
            'invalid' => InvalidCapabilityProvider::class,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(AgentCapabilityProvider::class);

        app(AgentCapabilityRegistry::class)->documents();
    }
}

class TestBusinessCapabilityProvider implements AgentCapabilityProvider
{
    public function capabilities(): iterable
    {
        yield new AgentCapabilityDocument(
            id: 'business_action:create_invoice',
            text: 'Create invoice from customer and line items.',
            payload: ['operation' => 'create']
        );

        yield [
            'id' => 'business_action:query_invoices',
            'text' => 'Query invoices by customer, status, due date, or amount.',
            'payload' => ['operation' => 'query'],
        ];

        yield [
            'id' => '',
            'text' => 'Ignored because id is empty.',
        ];

        yield [
            'id' => 'business_action:ignored_empty_text',
            'text' => '',
        ];

        yield new AgentCapabilityDocument(
            id: 'business_action:create_invoice',
            text: 'Create invoice replacement from second provider row.',
            payload: ['operation' => 'create']
        );
    }
}

class InvalidCapabilityProvider
{
}
