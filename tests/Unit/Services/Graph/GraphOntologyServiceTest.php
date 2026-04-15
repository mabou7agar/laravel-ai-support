<?php

namespace LaravelAIEngine\Tests\Unit\Services\Graph;

use LaravelAIEngine\Services\Graph\GraphOntologyService;
use LaravelAIEngine\Tests\UnitTestCase;

class GraphOntologyServiceTest extends UnitTestCase
{
    public function test_it_merges_enabled_ontology_packs(): void
    {
        config()->set('ai-engine.graph.ontology.enabled_packs', ['messaging', 'crm']);

        $service = new GraphOntologyService();

        $this->assertContains('post', $service->preferredModelTypesForCollections(['App\\Models\\Message']));
        $this->assertContains('HAS_MESSAGE', $service->relationTypesForQuery('show posts in channel context'));
        $this->assertContains('FOR_PROSPECT', $service->relationTypesForQuery('show prospect leads for Apollo'));
        $this->assertContains('lead', $service->preferredModelTypesForQuery('show prospect leads for Apollo'));
    }

    public function test_it_infers_pair_specific_relation_types(): void
    {
        $service = new GraphOntologyService();

        $this->assertSame('SENT_TO', $service->relationTypeFor('recipientUsers', 'App\\Models\\Mail', 'App\\Models\\User'));
        $this->assertSame('IN_THREAD', $service->relationTypeFor('thread', 'App\\Models\\Mail', 'App\\Models\\Thread'));
    }
}
