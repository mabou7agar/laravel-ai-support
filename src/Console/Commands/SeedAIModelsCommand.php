<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Enums\EntityEnum;
use LaravelAIEngine\Models\AIModel;

class SeedAIModelsCommand extends Command
{
    protected $signature = 'ai:models:seed
                            {--fresh : Overwrite metadata of models that already exist}';

    protected $description = 'Seed the ai_models table from the package model catalog (resources/models.json)';

    public function handle(): int
    {
        $path = dirname(__DIR__, 3) . '/resources/models.json';

        if (!is_file($path)) {
            $this->error("Model catalog not found at {$path}");
            return self::FAILURE;
        }

        $catalog = json_decode((string) file_get_contents($path), true);

        if (!is_array($catalog) || $catalog === []) {
            $this->error('Model catalog is empty or invalid JSON.');
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($catalog as $modelId => $meta) {
            $existing = AIModel::withTrashed()->where('model_id', $modelId)->first();

            if ($existing && !$this->option('fresh')) {
                $skipped++;
                continue;
            }

            $attributes = [
                'provider' => $meta['engine'] ?? 'openai',
                'name' => $meta['label'] ?? $modelId,
                'max_tokens' => $meta['max_tokens'] ?? null,
                'supports_vision' => (bool) ($meta['supports_vision'] ?? false),
                'supports_streaming' => (bool) ($meta['supports_streaming'] ?? true),
                'is_active' => true,
                'metadata' => [
                    'credit_index' => $meta['credit_index'] ?? 1.0,
                    'content_type' => $meta['content_type'] ?? 'text',
                    'driver_class' => $meta['driver_class'] ?? null,
                    'seeded_from' => 'package-catalog',
                ],
            ];

            if ($existing) {
                $existing->restore();
                $existing->update($attributes);
                $updated++;
            } else {
                AIModel::create(['model_id' => $modelId] + $attributes);
                $created++;
            }
        }

        EntityEnum::flushRuntimeCache();

        $this->info("Seeded model catalog: {$created} created, {$updated} updated, {$skipped} already present.");
        $this->line('Run with --fresh to overwrite existing rows from the catalog.');

        return self::SUCCESS;
    }
}
