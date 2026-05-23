<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\DTOs\LearningSourceRequest;
use LaravelAIEngine\Services\Learning\LearningService;

class LearnCommand extends Command
{
    protected $signature = 'ai:learn
                            {source : Text, URL, file path, or getdesign slug}
                            {--source-type= : text|url|file|getdesign_slug}
                            {--adapter= : Optional learning adapter, for example getdesign}
                            {--type=general : Learned knowledge type, for example design, workflow, reply_style}
                            {--title= : Human readable title}
                            {--user= : Scope user id}
                            {--tenant= : Scope tenant id}
                            {--workspace= : Scope workspace id}
                            {--session= : Scope session id}
                            {--index : Add the learned source to the configured vector store registry}
                            {--store-id= : Existing vector store id}
                            {--store-name=Learned Knowledge : Vector store name when creating a store}
                            {--metadata=* : Metadata as key=value, repeatable}
                            {--json : Output JSON result}';

    protected $description = 'Learn reusable package knowledge from text, files, URLs, or getdesign design slugs';

    public function handle(LearningService $learning): int
    {
        $source = (string) $this->argument('source');
        $sourceType = (string) ($this->option('source-type') ?: $this->guessSourceType($source));
        $adapter = $this->option('adapter');

        if ($sourceType === 'getdesign_slug') {
            $adapter = $adapter ?: 'getdesign';
        }

        $result = $learning->ingest(new LearningSourceRequest(
            sourceType: $sourceType,
            source: $source,
            type: (string) $this->option('type'),
            title: $this->option('title') ? (string) $this->option('title') : null,
            adapter: is_string($adapter) && $adapter !== '' ? $adapter : null,
            metadata: $this->metadata(),
            userId: $this->option('user') ?: null,
            tenantId: $this->option('tenant') ?: null,
            workspaceId: $this->option('workspace') ?: null,
            sessionId: $this->option('session') ? (string) $this->option('session') : null,
            shouldIndex: (bool) $this->option('index'),
            vectorStoreId: $this->option('store-id') ? (string) $this->option('store-id') : null,
            vectorStoreName: (string) $this->option('store-name'),
        ));

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Learned source saved.');
        $this->table(['Field', 'Value'], [
            ['Source ID', $result->source->sourceId],
            ['Type', $result->source->type],
            ['Source Type', $result->source->sourceType],
            ['Adapter', $result->source->adapter ?? '(none)'],
            ['Items', (string) $result->itemsCount],
            ['Vector Store', $result->vectorStoreId ?? '(not indexed)'],
        ]);

        return self::SUCCESS;
    }

    protected function guessSourceType(string $source): string
    {
        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return 'url';
        }

        if (is_file($source)) {
            return 'file';
        }

        if (preg_match('/^[a-zA-Z0-9._-]+$/', $source) === 1) {
            return 'getdesign_slug';
        }

        return 'text';
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
