<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Media;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use LaravelAIEngine\Exceptions\DocumentExtractionException;
use LaravelAIEngine\Services\Media\DocumentService;
use LaravelAIEngine\Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    private function tempFile(string $contents, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ai-engine-doc-test-') . $suffix;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_get_metadata_uses_finfo_for_mime_detection(): void
    {
        $path = $this->tempFile("# Title\nplain text body");

        try {
            $service = new DocumentService();
            $metadata = $service->getMetadata($path);
        } finally {
            @unlink($path);
        }

        // finfo classifies a plain UTF-8 file as text/plain.
        $this->assertSame('text/plain', $metadata['mime_type']);
        $this->assertIsInt($metadata['file_size']);
    }

    public function test_missing_extractor_throws_explicit_exception_when_graceful_degradation_disabled(): void
    {
        // smalot/pdfparser is not installed in the test environment, and the
        // CLI fallback / regex path will fail for a non-PDF blob, so this
        // exercises the "no library, no tool" failure branch.
        Config::set('ai-engine.media.document_extraction.graceful_degradation', false);

        $path = $this->tempFile('not really a pdf', '.pdf');

        $this->expectException(DocumentExtractionException::class);

        try {
            (new DocumentService())->extractText($path, 'pdf');
        } finally {
            @unlink($path);
        }
    }

    public function test_graceful_degradation_returns_empty_but_logs_failure(): void
    {
        Config::set('ai-engine.media.document_extraction.graceful_degradation', true);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Document text extraction failed'
                    && ($context['extension'] ?? null) === 'pdf'
                    && ($context['graceful_degradation'] ?? null) === true;
            });

        $path = $this->tempFile('not really a pdf', '.pdf');

        try {
            $result = (new DocumentService())->extractText($path, 'pdf');
        } finally {
            @unlink($path);
        }

        $this->assertSame('', $result);
    }

    public function test_config_max_file_size_limit_is_honored(): void
    {
        Config::set('ai-engine.media.document_extraction.graceful_degradation', false);
        Config::set('ai-engine.media.document_extraction.max_file_size', 5);

        // Comfortably larger than the 5-byte cap.
        $path = $this->tempFile(str_repeat('A', 50), '.txt');

        try {
            $this->expectException(DocumentExtractionException::class);
            $this->expectExceptionMessageMatches('/size limit/');

            (new DocumentService())->extractText($path, 'txt');
        } finally {
            @unlink($path);
        }
    }

    public function test_config_max_file_size_zero_disables_cap(): void
    {
        Config::set('ai-engine.media.document_extraction.max_file_size', 0);

        $path = $this->tempFile('hello world', '.txt');

        try {
            $result = (new DocumentService())->extractText($path, 'txt');
        } finally {
            @unlink($path);
        }

        $this->assertSame('hello world', $result);
    }

    public function test_pdftotext_non_zero_exit_does_not_leak_temp_file(): void
    {
        // Graceful degradation lets the failed extraction return cleanly so the
        // assertion focuses purely on temp-file cleanup.
        Config::set('ai-engine.media.document_extraction.graceful_degradation', true);

        $pattern = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pdf_*';
        $before = glob($pattern) ?: [];

        // Force the pdftotext branch on, then feed it a non-PDF blob so it
        // exits non-zero — exercising the "available but failed" path that
        // previously leaked the tempnam() file.
        $service = new class extends DocumentService {
            protected function isPdfToTextAvailable(): bool
            {
                return true;
            }
        };

        $path = $this->tempFile('not really a pdf', '.pdf');

        try {
            $result = $service->extractText($path, 'pdf');
        } finally {
            @unlink($path);
        }

        $after = glob($pattern) ?: [];
        $leaked = array_diff($after, $before);

        $this->assertSame('', $result);
        $this->assertSame([], $leaked, 'pdftotext failure leaked a temp file: ' . implode(', ', $leaked));
    }
}
