<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\Media\DocumentIngestionService;

class DocsIndexCommand extends Command
{
    protected $signature = 'ai:docs-index
                            {path : Local file/directory path or storage path when --disk is used}
                            {--disk= : Laravel storage disk for the path}
                            {--store-id= : Existing vector store id}
                            {--store-name=Documents : Vector store name when creating a store}
                            {--recursive : Include nested files for local directories}
                            {--extension=* : Allowed extension filter, repeatable}
                            {--metadata=* : Metadata as key=value, repeatable}
                            {--json : Output JSON result}';

    protected $description = 'Extract and index document files into the package vector store document registry';

    public function handle(DocumentIngestionService $ingestion): int
    {
        $documents = $this->documents();

        if ($documents === []) {
            $this->warn('No documents found to index.');
            return self::SUCCESS;
        }

        $metadata = $this->metadata();
        $storeId = $this->option('store-id') ?: null;
        $results = [];

        foreach ($documents as $document) {
            $result = $ingestion->ingest(
                $document,
                storeId: $storeId,
                storeName: (string) $this->option('store-name'),
                metadata: $metadata
            );

            $storeId = $result->storeId;
            $results[] = $result->toArray();
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'store_id' => $storeId,
                'indexed' => count($results),
                'documents' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Indexed ' . count($results) . ' document(s).');
        $this->table(
            ['Store', 'Document', 'Source', 'Length'],
            array_map(static fn (array $result): array => [
                $result['store_id'],
                $result['document_id'],
                $result['source'],
                $result['metadata']['content_length'] ?? 0,
            ], $results)
        );

        return self::SUCCESS;
    }

    /**
     * @return array<int, Document>
     */
    protected function documents(): array
    {
        $path = (string) $this->argument('path');
        $disk = $this->option('disk');

        if (is_string($disk) && $disk !== '') {
            return [Document::fromStorage($path, $disk)];
        }

        $paths = is_dir($path)
            ? $this->localDirectoryFiles($path)
            : [$path];

        $extensions = array_map('strtolower', array_filter((array) $this->option('extension')));

        return array_values(array_map(
            static fn (string $file): Document => Document::fromPath($file),
            array_filter($paths, function (string $file) use ($extensions): bool {
                return is_file($file)
                    && ($extensions === [] || in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true));
            })
        ));
    }

    /**
     * @return array<int, string>
     */
    protected function localDirectoryFiles(string $path): array
    {
        $iterator = $this->option('recursive')
            ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS))
            : new \IteratorIterator(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS));

        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    protected function metadata(): array
    {
        $metadata = [];

        foreach ((array) $this->option('metadata') as $entry) {
            if (!is_string($entry) || !str_contains($entry, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $entry, 2);
            $key = trim($key);

            if ($key !== '') {
                $metadata[$key] = trim($value);
            }
        }

        return $metadata;
    }
}
