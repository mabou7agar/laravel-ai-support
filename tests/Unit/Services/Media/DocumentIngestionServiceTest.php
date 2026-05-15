<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\Media\DocumentIngestionService;
use LaravelAIEngine\Tests\TestCase;

class DocumentIngestionServiceTest extends TestCase
{
    public function test_document_ingestion_extracts_text_and_persists_vector_store_document_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-engine-ingest-');
        file_put_contents($path, "Package docs\n\nTools can read indexed documents.");

        try {
            $result = app(DocumentIngestionService::class)->ingest(
                Document::fromPath($path, ['tenant_id' => 'tenant-1']),
                storeName: 'Package Docs',
                metadata: ['workspace_id' => 'workspace-1']
            );

            $loaded = app(\LaravelAIEngine\Services\SDK\VectorStoreService::class)->get($result->storeId);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertSame('Package Docs', $result->store['name']);
        $this->assertStringContainsString('indexed documents', $result->content);
        $this->assertSame($result->storeId, $loaded['id']);
        $this->assertSame('tenant-1', $loaded['documents'][0]['metadata']['tenant_id']);
        $this->assertSame('workspace-1', $loaded['documents'][0]['metadata']['workspace_id']);
        $this->assertSame('Package docs', substr($loaded['documents'][0]['metadata']['extracted_text'], 0, 12));
    }
}
