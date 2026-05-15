<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Support;

use LaravelAIEngine\Tests\UnitTestCase;

class PackageInfrastructureTest extends UnitTestCase
{
    public function test_pest_support_is_declared_and_bootstrapped(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('pestphp/pest', $composer['require-dev']);
        $this->assertFileExists(__DIR__.'/../../../tests/Pest.php');
    }

    public function test_published_ai_engine_config_is_annotated_not_only_delegated(): void
    {
        $config = file_get_contents(__DIR__.'/../../../config/ai-engine.php');

        $this->assertStringContainsString('Engine Defaults', $config);
        $this->assertStringContainsString("'engines'", $config);
        $this->assertGreaterThan(40, substr_count($config, "\n"));
    }

    public function test_all_source_files_declare_strict_types(): void
    {
        $missing = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../src')) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if (!str_contains(substr($contents, 0, 120), 'declare(strict_types=1);')) {
                $missing[] = str_replace(dirname(__DIR__, 3).'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_vectorizable_delegates_rag_and_search_document_helpers_to_concerns(): void
    {
        $root = dirname(__DIR__, 3);
        $vectorizable = file_get_contents($root.'/src/Traits/Vectorizable.php');

        $this->assertFileExists($root.'/src/Traits/Concerns/HasVectorContent.php');
        $this->assertFileExists($root.'/src/Traits/Concerns/HasVectorSearchDocuments.php');
        $this->assertFileExists($root.'/src/Traits/Concerns/HasVectorRAGMethods.php');
        $this->assertStringContainsString('use HasVectorContent;', $vectorizable);
        $this->assertStringContainsString('use HasVectorSearchDocuments;', $vectorizable);
        $this->assertStringContainsString('use HasVectorRAGMethods;', $vectorizable);
        $this->assertStringNotContainsString("\n    public static function intelligentChat", $vectorizable);
        $this->assertStringNotContainsString("\n    public function getVectorContentChunks", $vectorizable);
        $this->assertStringNotContainsString("\n    public function toSearchDocument", $vectorizable);
    }
}
