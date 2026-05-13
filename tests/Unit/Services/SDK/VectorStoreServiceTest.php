<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\SDK;

use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\VectorStoreService;
use LaravelAIEngine\Tests\TestCase;

class VectorStoreServiceTest extends TestCase
{
    public function test_vector_store_service_persists_stores_and_documents_when_tables_exist(): void
    {
        $service = app(VectorStoreService::class);

        $store = $service->create('Knowledge Base', ['tenant' => 'acme']);

        $path = tempnam(sys_get_temp_dir(), 'ai-engine-doc-');
        file_put_contents($path, 'Laravel AI vector store document.');

        try {
            $updated = $service->add($store['id'], Document::fromPath($path, ['status' => 'published']));
            $loaded = $service->get($store['id']);
        } finally {
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }

        $this->assertSame($store['id'], $updated['id']);
        $this->assertSame('Knowledge Base', $loaded['name']);
        $this->assertSame(['tenant' => 'acme'], $loaded['metadata']);
        $this->assertCount(1, $loaded['documents']);
        $this->assertSame(['status' => 'published'], $loaded['documents'][0]['metadata']);
    }
}
