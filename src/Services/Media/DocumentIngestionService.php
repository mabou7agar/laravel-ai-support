<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\DTOs\DocumentIngestionResult;
use LaravelAIEngine\Files\Document;
use LaravelAIEngine\Services\SDK\VectorStoreService;

class DocumentIngestionService
{
    public function __construct(
        protected DocumentService $documents,
        protected VectorStoreService $vectorStores,
    ) {}

    public function ingest(
        Document|string $document,
        ?string $storeId = null,
        string $storeName = 'Documents',
        array $metadata = [],
        ?string $extension = null
    ): DocumentIngestionResult {
        $document = is_string($document)
            ? Document::fromPath($document)
            : $document;

        [$content, $resolvedExtension] = $this->extract($document, $extension);

        $documentMetadata = array_filter([
            ...$document->metadata,
            ...$metadata,
            'extension' => $resolvedExtension,
            'content_length' => strlen($content),
            'extracted_text' => $content,
        ], static fn ($value): bool => $value !== null);

        $document = new Document($document->source, $document->disk, $documentMetadata);

        $store = $storeId !== null
            ? $this->vectorStores->get($storeId)
            : null;

        if ($store === null) {
            $store = $this->vectorStores->create($storeName, [
                'source' => 'document_ingestion',
            ]);
            $storeId = (string) $store['id'];
        }

        $store = $this->vectorStores->add($storeId, $document);

        return new DocumentIngestionResult(
            storeId: (string) $store['id'],
            documentId: $document->id(),
            source: $document->source,
            disk: $document->disk,
            content: $content,
            metadata: $documentMetadata,
            store: $store,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function extract(Document $document, ?string $extension = null): array
    {
        $extension = $extension ?: strtolower((string) pathinfo($document->source, PATHINFO_EXTENSION));

        if ($document->disk === null) {
            return [$this->documents->extractText($document->source, $extension), $extension];
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'ai-engine-doc-ingest-');
        if (!is_string($tempPath)) {
            throw new \RuntimeException('Unable to create temporary document ingestion file.');
        }

        try {
            file_put_contents($tempPath, Storage::disk($document->disk)->get($document->source));

            return [$this->documents->extractText($tempPath, $extension), $extension];
        } finally {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}
