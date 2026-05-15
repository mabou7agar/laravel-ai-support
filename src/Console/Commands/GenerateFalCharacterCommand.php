<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Fal\FalAsyncCharacterGenerationService;
use LaravelAIEngine\Services\Fal\FalCharacterGenerationService;

class GenerateFalCharacterCommand extends Command
{
    protected $signature = 'ai:generate-character
                            {prompt? : Prompt used to generate the character}
                            {--name= : Character display name}
                            {--save-as= : Alias used later with --use-character}
                            {--voice-id= : Optional ElevenLabs voice ID to attach for consistent TTS}
                            {--voice-stability= : Optional ElevenLabs stability setting between 0 and 1}
                            {--voice-similarity-boost= : Optional ElevenLabs similarity boost between 0 and 1}
                            {--voice-style= : Optional ElevenLabs style setting between 0 and 1}
                            {--voice-speaker-boost=1 : Enable or disable ElevenLabs speaker boost}
                            {--from-character= : Expand a previously approved preview/character alias}
                            {--user-id= : User ID used for credit checks and deductions}
                            {--frame-count=3 : Number of generated views/images}
                            {--look-size=4 : Number of consistent views per styling look before switching style}
                            {--aspect-ratio= : Aspect ratio like 9:16 or 16:9}
                            {--resolution= : Output resolution like 1K}
                            {--seed= : Optional generation seed}
                            {--thinking-level= : Nano Banana thinking level minimal|high}
                            {--output-format= : Output format like png or jpeg}
                            {--preview-only : Generate and save only the base preview image}
                            {--sync : Run the full character workflow inline instead of queueing it}
                            {--wait : Wait for an async character workflow to finish}
                            {--timeout=900 : Max seconds to wait when using --wait}
                            {--poll-interval=5 : Seconds between status checks when using --wait}
                            {--job-status= : Inspect an existing queued character job}
                            {--dry-run : Show the request without calling FAL}}';

    protected $description = 'Generate a reusable character reference set with Nano Banana and save it for later video prompts';

    public function handle(
        FalCharacterGenerationService $characterGenerationService,
        FalAsyncCharacterGenerationService $asyncCharacterGenerationService
    ): int
    {
        try {
            $jobStatusId = $this->option('job-status');
            if (is_string($jobStatusId) && trim($jobStatusId) !== '') {
                $status = $asyncCharacterGenerationService->getStatus(trim($jobStatusId));
                if ($status === null) {
                    $this->components->error('Character generation job was not found.');
                    return self::FAILURE;
                }

                $this->components->info('Fetched character generation job status');
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $prompt = trim((string) ($this->argument('prompt') ?? ''));
            if ($prompt === '') {
                $this->components->error('Prompt is required unless --job-status is used.');
                return self::FAILURE;
            }

            $parameters = $this->buildOptions();
            $userId = $this->option('user-id');
            $workflow = $characterGenerationService->prepareWorkflow(
                $prompt,
                $parameters,
                is_string($userId) ? $userId : null
            );

            $this->components->info('Prepared character generation workflow');
            $this->line(json_encode([
                'requested_views' => count($workflow),
                'steps' => array_map(static fn (array $step): array => [
                    'step' => $step['step'],
                    'look_index' => $step['look_index'] ?? null,
                    'look_variant' => $step['look_variant'] ?? null,
                    'view' => $step['view'],
                    'label' => $step['label'],
                    'model' => $step['model'],
                    'parameters' => $step['parameters'],
                ], $workflow),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ((bool) $this->option('dry-run')) {
                $this->components->info('Dry run complete.');
                return self::SUCCESS;
            }

            if (!(bool) $this->option('sync')) {
                $submitted = $asyncCharacterGenerationService->submit(
                    $prompt,
                    $parameters,
                    is_string($userId) ? $userId : null
                );

                $this->components->info('Character generation workflow queued');
                $this->line(json_encode([
                    'job_id' => $submitted['job_id'],
                    'status' => $submitted['status']['status'] ?? 'queued',
                    'job' => $submitted['status'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if (!(bool) $this->option('wait')) {
                    return self::SUCCESS;
                }

                $status = $asyncCharacterGenerationService->waitForCompletion(
                    $submitted['job_id'],
                    max(1, (int) $this->option('timeout')),
                    max(1, (int) $this->option('poll-interval'))
                );

                $this->components->info('Character generation workflow finished');
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return ($status['status'] ?? null) === 'completed' ? self::SUCCESS : self::FAILURE;
            }

            $this->components->info('Submitting character generation workflow');
            $result = $characterGenerationService->generateAndStore(
                $prompt,
                $parameters,
                is_string($userId) ? $userId : null,
                function (array $progress): void {
                    $this->line(sprintf(
                        'Generating view %d/%d: %s',
                        $progress['step'],
                        $progress['total_steps'],
                        $progress['label']
                    ));
                }
            );
            $alias = $result['alias'];
            $character = $result['character'];

            $this->components->info("Character saved as '{$alias}'");
            $this->table(
                ['Field', 'Value'],
                [
                    ['name', $character['name']],
                    ['alias', $alias],
                    ['frontal_image_url', $character['frontal_image_url']],
                    ['reference_image_urls', implode("\n", $character['reference_image_urls']) ?: '(none)'],
                    ['voice_id', $character['voice_id'] ?? '(none)'],
                ]
            );

            $this->line('Use it with:');
            if (($parameters['preview_only'] ?? false) === true) {
                $targetCount = max(2, (int) ($parameters['frame_count'] ?? 4));
                $this->line("php artisan ai:generate-character \"{$prompt}\" --from-character={$alias} --frame-count={$targetCount} --look-size=" . (int) ($parameters['look_size'] ?? 4));
            } else {
                $this->line("php artisan ai:test-fal-media \"Your scene prompt\" --use-character={$alias}");
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
            'name' => $this->option('name'),
            'save_as' => $this->option('save-as'),
            'from_character' => $this->option('from-character'),
            'frame_count' => max(1, (int) $this->option('frame-count')),
            'look_size' => max(1, (int) $this->option('look-size')),
            'preview_only' => (bool) $this->option('preview-only'),
        ];

        foreach (['aspect-ratio' => 'aspect_ratio', 'resolution' => 'resolution', 'thinking-level' => 'thinking_level', 'output-format' => 'output_format'] as $option => $parameter) {
            $value = $this->option($option);
            if (is_string($value) && trim($value) !== '') {
                $options[$parameter] = trim($value);
            }
        }

        $seed = $this->option('seed');
        if ($seed !== null && $seed !== '') {
            $options['seed'] = (int) $seed;
        }

        $voiceId = $this->option('voice-id');
        if (is_string($voiceId) && trim($voiceId) !== '') {
            $options['voice_id'] = trim($voiceId);
        }

        $voiceSettings = [];
        foreach ([
            'voice-stability' => 'stability',
            'voice-similarity-boost' => 'similarity_boost',
            'voice-style' => 'style',
        ] as $option => $key) {
            $value = $this->option($option);
            if ($value !== null && $value !== '') {
                $voiceSettings[$key] = (float) $value;
            }
        }

        $speakerBoost = filter_var(
            (string) $this->option('voice-speaker-boost'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($speakerBoost !== null) {
            $voiceSettings['use_speaker_boost'] = $speakerBoost;
        }

        if ($voiceSettings !== []) {
            $options['voice_settings'] = $voiceSettings;
        }

        return $options;
    }
}
