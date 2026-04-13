<?php

declare(strict_types=1);

namespace LaravelAIEngine\Tests\Unit\Documentation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocsSiteIntegrityTest extends TestCase
{
    private const REQUIRED_RUNTIME_PAGE_STRINGS = [
        'index' => ['UnifiedEngineManager', 'AIEngineService', 'DriverRegistry'],
        'guides/architecture' => ['UnifiedEngineManager', 'EngineProxy', 'AIEngineService', 'DriverRegistry'],
        'guides/quickstart' => ['UnifiedEngineManager', 'AIEngineService'],
        'guides/concepts' => ['UnifiedEngineManager', 'AIEngineService', 'EngineProxy'],
        'guides/direct-generation-recipes' => ['UnifiedEngineManager', 'AIEngineService', 'DriverRegistry'],
        'guides/testing-playbook' => ['DriverRegistry', 'EngineDriverInterface', 'AIEngineService'],
        'reference/upgrade' => ['AIEngineManager', 'EngineBuilder', 'UnifiedEngineManager', 'AIEngineService'],
    ];

    public function test_every_registered_docs_page_exists_as_mdx_file(): void
    {
        foreach ($this->docsPages() as $page) {
            self::assertFileExists($this->pagePath($page), "Missing docs page for [{$page}]");
        }
    }

    public function test_every_mdx_file_is_registered_in_docs_navigation(): void
    {
        $registered = $this->docsPages();
        $actualFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::docsRoot(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'mdx') {
                continue;
            }

            $actualFiles[] = str_replace('.mdx', '', str_replace(self::docsRoot() . '/', '', $file->getPathname()));
        }

        sort($registered);
        sort($actualFiles);

        self::assertSame($registered, $actualFiles, 'docs-site navigation and MDX files are out of sync.');
    }

    #[DataProvider('pageProvider')]
    public function test_registered_page_has_title_and_description_frontmatter(string $page): void
    {
        $contents = file_get_contents($this->pagePath($page));
        self::assertIsString($contents);
        self::assertMatchesRegularExpression('/\A---\s.*?^title:\s.+^description:\s.+^---\s/ms', $contents, "Missing title/description frontmatter for [{$page}]");
    }

    #[DataProvider('runtimePageProvider')]
    public function test_core_runtime_pages_reference_current_runtime_entrypoints(string $page, array $requiredStrings): void
    {
        $contents = file_get_contents($this->pagePath($page));
        self::assertIsString($contents);

        foreach ($requiredStrings as $requiredString) {
            self::assertStringContainsString($requiredString, $contents, "[{$page}] is missing runtime term [{$requiredString}]");
        }
    }

    public static function pageProvider(): array
    {
        return array_map(fn (string $page): array => [$page], self::readDocsPages());
    }

    public static function runtimePageProvider(): array
    {
        $rows = [];

        foreach (self::REQUIRED_RUNTIME_PAGE_STRINGS as $page => $requiredStrings) {
            $rows[$page] = [$page, $requiredStrings];
        }

        return $rows;
    }

    private function docsPages(): array
    {
        return self::readDocsPages();
    }

    private static function readDocsPages(): array
    {
        $docsJson = json_decode((string) file_get_contents(self::docsRoot() . '/docs.json'), true, 512, JSON_THROW_ON_ERROR);
        $pages = [];

        foreach (($docsJson['navigation']['tabs'] ?? []) as $tab) {
            foreach (($tab['groups'] ?? []) as $group) {
                foreach (($group['pages'] ?? []) as $page) {
                    if (is_string($page) && $page !== '') {
                        $pages[] = $page;
                    }
                }
            }
        }

        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    private static function docsRoot(): string
    {
        return dirname(__DIR__, 3) . '/docs-site';
    }

    private function pagePath(string $page): string
    {
        return self::docsRoot() . '/' . $page . '.mdx';
    }
}
