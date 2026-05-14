<?php

namespace LaravelAIEngine\Tests\Unit\Services\RAG;

use LaravelAIEngine\Services\RAG\RAGContextService;
use LaravelAIEngine\Services\RAG\RAGModelMetadataService;
use LaravelAIEngine\Services\RAG\RAGDecisionPolicy;
use LaravelAIEngine\Tests\UnitTestCase;

class RAGContextServiceTest extends UnitTestCase
{
    public function test_build_merges_remote_node_collections_into_models_context(): void
    {
        $metadata = $this->createMock(RAGModelMetadataService::class);
        $metadata->method('getAvailableModels')->willReturn([
            [
                'name' => 'document',
                'class' => 'App\\Models\\Document',
                'table' => 'documents',
                'description' => 'Document',
                'location' => 'local',
                'capabilities' => ['db_query' => true],
                'schema' => [],
                'filter_config' => [],
                'tools' => [],
            ],
        ]);

        $service = new class($metadata, new RAGDecisionPolicy()) extends RAGContextService
        {
            public function getAvailableNodes(): array
            {
                return [[
                    'slug' => 'inbusiness',
                    'name' => 'InBusiness',
                    'description' => 'Remote business node',
                    'collections' => ['invoice', 'order'],
                ]];
            }
        };

        $context = $service->build('list invoices', [], 1, []);
        $modelNames = collect($context['models'])->pluck('name')->all();

        $this->assertContains('document', $modelNames);
        $this->assertContains('invoice', $modelNames);
        $this->assertContains('order', $modelNames);

        $invoice = collect($context['models'])->firstWhere('name', 'invoice');
        $this->assertSame('remote', $invoice['location'] ?? null);
        $this->assertSame('inbusiness', $invoice['node_slug'] ?? null);
    }
}

