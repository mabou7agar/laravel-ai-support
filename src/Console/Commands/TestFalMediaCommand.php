<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Fal\FalAsyncVideoService;
use LaravelAIEngine\Services\Fal\FalMediaWorkflowService;

class TestFalMediaCommand extends Command
{
    protected $signature = 'ai-engine:test-fal-media
                            {prompt? : Prompt to send to FAL}
                            {--model= : FAL model slug. Auto-resolved when omitted}
                            {--user-id= : User ID used for credit checks and deductions}
                            {--frame-count=1 : Number of Nano Banana images to generate}
                            {--duration=5 : Video duration}
                            {--aspect-ratio= : Aspect ratio like 16:9}
                            {--resolution= : Output resolution like 720p or 1K}
                            {--seed= : Optional seed}
                            {--mode= : Nano Banana mode generate|edit}
                            {--thinking-level= : Nano Banana thinking level minimal|high}
                            {--output-format= : Output format like png or jpeg}
                            {--start-image-url= : Start frame image URL}
                            {--end-image-url= : End frame image URL}
                            {--source-image=* : Source image URL(s) for edit workflows}
                            {--reference-image-url=* : Reference image URL(s) for Kling/Seedance}
                            {--character=* : Character JSON object; repeatable}
                            {--use-character=* : Saved character alias(es) from ai-engine:generate-character}
                            {--use-last-character : Reuse the most recently generated character}
                            {--shot=* : Shot JSON object with prompt and optional duration; repeatable}
                            {--generate-audio=1 : Enable or disable native audio generation}
                            {--async : Submit video generation to FAL queue instead of waiting}
                            {--wait : Wait for an async submission to finish by polling local job status}
                            {--timeout=180 : Timeout in seconds when used with --wait}
                            {--poll-interval=5 : Poll interval in seconds when used with --wait}
                            {--job-status= : Inspect an existing async local job id}
                            {--refresh-status : Refresh provider status when inspecting a job}
                            {--parameter=* : Extra provider parameter in key=value form}
                            {--dry-run : Show the normalized request without calling the provider}}';

    protected $description = 'Test FAL Nano Banana, Kling O3, and Seedance 2.0 media generation';

    public function handle(FalMediaWorkflowService $mediaWorkflowService, FalAsyncVideoService $falAsyncVideoService): int
    {
        try {
            $jobStatusId = $this->option('job-status');
            if (is_string($jobStatusId) && trim($jobStatusId) !== '') {
                $status = $falAsyncVideoService->getStatus(trim($jobStatusId), (bool) $this->option('refresh-status'));
                if ($status === null) {
                    $this->components->error('Async job was not found.');
                    return self::FAILURE;
                }

                $this->components->info('Fetched async FAL media job status');
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $prompt = (string) ($this->argument('prompt') ?? '');
            $options = $this->buildOptions();
            $userId = $this->option('user-id');
            $request = $mediaWorkflowService->prepareRequest(
                $prompt,
                $options,
                is_string($userId) ? $userId : null
            );

            $this->components->info('Prepared FAL media request');
            $this->table(
                ['Field', 'Value'],
                [
                    ['engine', $request->getEngine()->value],
                    ['model', $request->getModel()->value],
                    ['content_type', $request->getContentType()],
                    ['prompt', $request->getPrompt() !== '' ? $request->getPrompt() : '(empty)'],
                ]
            );

            $this->line(json_encode($request->getParameters(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ((bool) $this->option('dry-run')) {
                $this->components->info('Dry run complete.');
                return self::SUCCESS;
            }

            if ((bool) $this->option('async')) {
                $submitted = $falAsyncVideoService->submit(
                    $prompt,
                    $options,
                    is_string($userId) ? $userId : null
                );

                $this->components->info('FAL media job submitted asynchronously');
                $this->line(json_encode([
                    'job_id' => $submitted['job_id'],
                    'status' => $submitted['status'],
                    'webhook_url' => $submitted['webhook_url'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if (!(bool) $this->option('wait')) {
                    return self::SUCCESS;
                }

                $status = $falAsyncVideoService->waitForCompletion(
                    $submitted['job_id'],
                    max(1, (int) $this->option('timeout')),
                    max(1, (int) $this->option('poll-interval'))
                );
                $publicStatus = $falAsyncVideoService->toPublicStatus($status);

                if (($status['status'] ?? null) !== 'completed') {
                    $this->components->error($status['metadata']['provider']['error'] ?? 'Async FAL media job failed.');
                    $this->line(json_encode($publicStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    return self::FAILURE;
                }

                $this->components->info('Async FAL media job completed');
                $this->line(json_encode($publicStatus, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
            }

            $result = $mediaWorkflowService->generate(
                $prompt,
                $options,
                is_string($userId) ? $userId : null
            );
            $response = $result['response'];

            if (!$response->isSuccessful()) {
                $this->components->error($response->getError() ?? 'FAL media request failed.');
                return self::FAILURE;
            }

            $this->components->info('FAL media request succeeded');

            $files = $response->getFiles();
            if ($files !== []) {
                $this->table(
                    ['#', 'File'],
                    array_map(
                        static fn (string $file, int $index): array => [$index + 1, $file],
                        $files,
                        array_keys($files)
                    )
                );
            }

            $usage = $response->getUsage();
            if (is_array($usage) && $usage !== []) {
                $this->line(json_encode(['usage' => $usage], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $metadata = $response->getMetadata();
            if ($metadata !== []) {
                $this->line(json_encode(['metadata' => $metadata], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $content = $response->getContent();
            if ($content !== '') {
                $this->line($content);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function buildOptions(): array
    {
        $options = [
            'use_demo_user_id' => false,
        ];

        $model = $this->option('model');
        if (is_string($model) && trim($model) !== '') {
            $options['model'] = trim($model);
        }

        $frameCount = (int) $this->option('frame-count');
        if ($frameCount > 0) {
            $options['frame_count'] = $frameCount;
        }

        $duration = $this->option('duration');
        if ($duration !== null && $duration !== '') {
            $options['duration'] = (string) $duration;
        }

        foreach (['aspect-ratio' => 'aspect_ratio', 'resolution' => 'resolution', 'mode' => 'mode', 'thinking-level' => 'thinking_level', 'output-format' => 'output_format'] as $option => $parameter) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $options[$parameter] = trim($value);
            }
        }

        $seed = $this->option('seed');
        if ($seed !== null && $seed !== '') {
            $options['seed'] = (int) $seed;
        }

        $startImageUrl = $this->option('start-image-url');
        if (is_string($startImageUrl) && trim($startImageUrl) !== '') {
            $options['start_image_url'] = trim($startImageUrl);
        }

        $endImageUrl = $this->option('end-image-url');
        if (is_string($endImageUrl) && trim($endImageUrl) !== '') {
            $options['end_image_url'] = trim($endImageUrl);
        }

        $sourceImages = $this->normalizeStringArray($this->option('source-image'));
        if ($sourceImages !== []) {
            $options['source_images'] = $sourceImages;
        }

        $referenceImageUrls = $this->normalizeStringArray($this->option('reference-image-url'));
        if ($referenceImageUrls !== []) {
            $options['reference_image_urls'] = $referenceImageUrls;
        }

        $characters = $this->parseCharacters($this->option('character'));
        if ($characters !== []) {
            $options['character_sources'] = $characters;
        }

        $shots = $this->parseShots($this->option('shot'));
        if ($shots !== []) {
            $options['multi_prompt'] = $shots;
        }

        $useCharacters = $this->normalizeStringArray($this->option('use-character'));
        if ($useCharacters !== []) {
            $options['use_characters'] = $useCharacters;
        }

        if ((bool) $this->option('use-last-character')) {
            $options['use_last_character'] = true;
        }

        $options['generate_audio'] = filter_var(
            (string) $this->option('generate-audio'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? true;

        $extraParameters = [];
        foreach ((array) $this->option('parameter') as $parameter) {
            if (!is_string($parameter) || !str_contains($parameter, '=')) {
                throw new \InvalidArgumentException("Invalid --parameter value [{$parameter}]. Expected key=value.");
            }

            [$key, $value] = explode('=', $parameter, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '') {
                throw new \InvalidArgumentException("Invalid --parameter value [{$parameter}]. Key cannot be empty.");
            }

            $extraParameters[$key] = $value;
        }

        if ($extraParameters !== []) {
            $options['parameters'] = $extraParameters;
        }

        return $options;
    }

    private function parseCharacters(array $characters): array
    {
        $parsed = [];

        foreach ($characters as $character) {
            if (!is_string($character) || trim($character) === '') {
                continue;
            }

            $decoded = json_decode($character, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Each --character option must be valid JSON.');
            }

            $parsed[] = $decoded;
        }

        return $parsed;
    }

    private function parseShots(array $shots): array
    {
        $parsed = [];

        foreach ($shots as $shot) {
            if (!is_string($shot) || trim($shot) === '') {
                continue;
            }

            $decoded = json_decode($shot, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('Each --shot option must be valid JSON.');
            }

            $prompt = trim((string) ($decoded['prompt'] ?? ''));
            if ($prompt === '') {
                throw new \InvalidArgumentException('Each --shot option must include a non-empty prompt.');
            }

            $normalized = ['prompt' => $prompt];

            if (isset($decoded['duration']) && $decoded['duration'] !== '') {
                $normalized['duration'] = (string) $decoded['duration'];
            }

            $parsed[] = $normalized;
        }

        return $parsed;
    }

    private function normalizeStringArray(array $values): array
    {
        return array_values(array_filter(array_map(static function ($value): ?string {
            if (!is_string($value)) {
                return null;
            }

            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }, $values)));
    }
}
