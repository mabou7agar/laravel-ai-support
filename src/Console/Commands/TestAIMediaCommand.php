<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LaravelAIEngine\Models\AIMedia;
use LaravelAIEngine\Services\AIMediaManager;

class TestAIMediaCommand extends Command
{
    protected $signature = 'ai:test-ai-media
                            {--write-test : Write a small test object to the configured media disk}
                            {--cleanup : Delete the test file and AIMedia row after writing it}
                            {--limit=5 : Number of recent AIMedia rows to display}
                            {--disk= : Override the configured media disk for the write test}
                            {--json : Output the diagnostic payload as JSON}';

    protected $description = 'Verify AIMedia configuration, persistence, and storage URL behavior';

    public function handle(AIMediaManager $mediaManager): int
    {
        try {
            $summary = [
                'enabled' => config('ai-engine.media_library.enabled', true),
                'persist_records' => config('ai-engine.media_library.persist_records', true),
                'disk' => (string) config('ai-engine.media_library.disk', 'public'),
                'directory' => (string) config('ai-engine.media_library.directory', 'ai-generated'),
                'visibility' => config('ai-engine.media_library.visibility', 'public'),
                'table_exists' => Schema::hasTable('ai_media'),
                'records_count' => Schema::hasTable('ai_media') ? AIMedia::query()->count() : 0,
            ];

            $writeTest = null;
            if ((bool) $this->option('write-test')) {
                $disk = $this->option('disk');
                $stored = $mediaManager->storeBinary(
                    'ai-media-health-check',
                    'health-check.txt',
                    [
                        'disk' => is_string($disk) && trim($disk) !== '' ? trim($disk) : null,
                        'engine' => 'system',
                        'ai_model' => 'ai-media-health-check',
                        'content_type' => 'document',
                        'collection_name' => 'diagnostics',
                        'name' => 'health-check',
                        'mime_type' => 'text/plain',
                    ]
                );

                $existsOnDisk = isset($stored['path'], $stored['disk']) && $stored['path'] !== null && $stored['disk'] !== null
                    ? Storage::disk($stored['disk'])->exists($stored['path'])
                    : false;

                $writeTest = [
                    'stored' => $stored,
                    'exists_on_disk' => $existsOnDisk,
                ];

                if ((bool) $this->option('cleanup') && ($stored['id'] ?? null) !== null) {
                    $media = AIMedia::query()->find($stored['id']);
                    if ($media !== null) {
                        if ($media->path !== null && $media->disk !== null) {
                            Storage::disk($media->disk)->delete($media->path);
                        }
                        $media->delete();
                    }

                    $writeTest['cleaned_up'] = true;
                }
            }

            $recent = Schema::hasTable('ai_media')
                ? AIMedia::query()
                    ->latest()
                    ->limit(max(1, (int) $this->option('limit')))
                    ->get([
                        'id',
                        'engine',
                        'ai_model',
                        'content_type',
                        'collection_name',
                        'disk',
                        'path',
                        'url',
                        'source_url',
                        'created_at',
                    ])
                    ->toArray()
                : [];

            $payload = [
                'summary' => $summary,
                'write_test' => $writeTest,
                'recent_media' => $recent,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $this->components->info('AIMedia diagnostics');
            $this->table(
                ['Key', 'Value'],
                [
                    ['enabled', $summary['enabled'] ? 'true' : 'false'],
                    ['persist_records', $summary['persist_records'] ? 'true' : 'false'],
                    ['disk', $summary['disk']],
                    ['directory', $summary['directory']],
                    ['visibility', (string) $summary['visibility']],
                    ['table_exists', $summary['table_exists'] ? 'true' : 'false'],
                    ['records_count', (string) $summary['records_count']],
                ]
            );

            if ($writeTest !== null) {
                $this->components->info('Write test');
                $this->line(json_encode($writeTest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            if ($recent !== []) {
                $this->components->info('Recent AIMedia records');
                $this->table(
                    ['id', 'engine', 'model', 'type', 'disk', 'url'],
                    array_map(
                        static fn (array $row): array => [
                            $row['id'],
                            $row['engine'],
                            $row['ai_model'],
                            $row['content_type'],
                            $row['disk'],
                            $row['url'],
                        ],
                        $recent
                    )
                );
            } else {
                $this->components->warn('No AIMedia records found.');
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
