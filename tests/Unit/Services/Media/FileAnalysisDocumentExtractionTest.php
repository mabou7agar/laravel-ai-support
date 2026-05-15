<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use Illuminate\Http\UploadedFile;
use LaravelAIEngine\Services\ChatService;
use LaravelAIEngine\Services\ConversationService;
use LaravelAIEngine\Services\FileAnalysisService;
use LaravelAIEngine\Tests\TestCase;
use Mockery;

class FileAnalysisDocumentExtractionTest extends TestCase
{
    public function test_file_analysis_uses_shared_document_extraction_for_markdown_files(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-engine-md-');
        file_put_contents($path, "# Install Guide\n\nRun php artisan ai:docs-index.");

        $file = new UploadedFile(
            $path,
            'guide.md',
            'text/markdown',
            null,
            true
        );

        try {
            $service = new FileAnalysisService(
                Mockery::mock(ChatService::class),
                Mockery::mock(ConversationService::class)
            );

            $content = $service->extractContent($file);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->assertStringContainsString('Install Guide', $content);
        $this->assertStringContainsString('ai:docs-index', $content);
    }
}
