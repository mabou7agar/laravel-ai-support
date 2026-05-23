<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Services\Learning;

use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\Adapters\FileLearningAdapter;
use LaravelAIEngine\Tests\UnitTestCase;

class FileLearningAdapterTest extends UnitTestCase
{
    public function test_local_file_learning_requires_explicit_allowed_path(): void
    {
        config()->set('ai-engine.learning.adapters.file.allow_local_paths', false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local filesystem learning is disabled');

        (new FileLearningAdapter())->fetch(new LearningSourceRequest(
            sourceType: 'file',
            source: __FILE__,
        ));
    }

    public function test_local_file_learning_is_limited_to_configured_roots(): void
    {
        config()->set('ai-engine.learning.adapters.file.allow_local_paths', true);
        config()->set('ai-engine.learning.adapters.file.allowed_paths', [__DIR__]);

        $payload = (new FileLearningAdapter())->fetch(new LearningSourceRequest(
            sourceType: 'file',
            source: __FILE__,
        ));

        $this->assertStringContainsString('FileLearningAdapterTest', $payload->content);
        $this->assertSame('file', $payload->metadata['source_type']);
    }

    public function test_local_file_learning_rejects_files_over_configured_size(): void
    {
        config()->set('ai-engine.learning.adapters.file.allow_local_paths', true);
        config()->set('ai-engine.learning.adapters.file.allowed_paths', [__DIR__]);
        config()->set('ai-engine.learning.max_content_bytes', 10);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds the configured size limit');

        (new FileLearningAdapter())->fetch(new LearningSourceRequest(
            sourceType: 'file',
            source: __FILE__,
        ));
    }
}
