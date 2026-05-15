<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Console\Commands;

use LaravelAIEngine\Models\AIVectorStore;
use LaravelAIEngine\Tests\TestCase;

class DocsIndexCommandTest extends TestCase
{
    public function test_docs_index_command_indexes_local_document_into_vector_store(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-engine-docs-command-');
        file_put_contents($path, "CLI docs\n\nIndex this document.");

        try {
            $this->artisan('ai:docs-index', [
                'path' => $path,
                '--store-name' => 'CLI Docs',
                '--metadata' => ['tenant_id=tenant-1'],
            ])
                ->assertExitCode(0);

            $store = AIVectorStore::query()->where('name', 'CLI Docs')->with('documents')->first();
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertNotNull($store);
        $this->assertCount(1, $store->documents);
        $this->assertSame('tenant-1', $store->documents[0]->metadata['tenant_id']);
        $this->assertStringContainsString('Index this document', $store->documents[0]->metadata['extracted_text']);
    }
}
