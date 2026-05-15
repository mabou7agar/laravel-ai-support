<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Fal\FalAsyncReferencePackGenerationService;
use LaravelAIEngine\Services\Fal\FalReferencePackGenerationService;

class GenerateFalReferencePackCommand extends Command
{
    protected $signature = 'ai:generate-reference-pack
                            {prompt? : Prompt used to generate the reference pack}
                            {--entity-type=character : character|object|furniture|vehicle|product|prop|creature}
                            {--name= : Reference pack display name}
                            {--save-as= : Alias used later with stored references}
                            {--from-reference-pack= : Expand a previously approved reference pack alias}
                            {--user-id= : User ID used for credit checks and deductions}
                            {--frame-count=3 : Number of generated views/images}
                            {--look-size=4 : Number of consistent views per styling look before switching style}
                            {--look-id= : Selected look identifier from your app or entity data}
                            {--look-payload= : Optional JSON payload with look label/instruction overrides}
                            {--look-set= : JSON array of selected stored looks used for strict_selected_set workflows}
                            {--look-mode= : Look workflow mode strict_selected_set|strict_stored|guided|vendor}
                            {--strict-stored-looks : Force the workflow to use only the stored/app-provided look across all views}
                            {--aspect-ratio= : Aspect ratio like 9:16 or 16:9}
                            {--resolution= : Output resolution like 1K}
                            {--seed= : Optional generation seed}
                            {--thinking-level= : Nano Banana thinking level minimal|high}
                            {--output-format= : Output format like png or jpeg}
                            {--preview-only : Generate and save only the base preview image}
                            {--sync : Run the full reference pack workflow inline instead of queueing it}
                            {--wait : Wait for an async reference pack workflow to finish}
                            {--timeout=900 : Max seconds to wait when using --wait}
                            {--poll-interval=5 : Seconds between status checks when using --wait}
                            {--job-status= : Inspect an existing queued reference pack job}
                            {--dry-run : Show the workflow without calling FAL}}';

    protected $description = 'Generate a reusable FAL Nano Banana reference pack for characters, objects, products, furniture, and more';

    public function handle(
        FalReferencePackGenerationService $referencePackGenerationService,
        FalAsyncReferencePackGenerationService $asyncReferencePackGenerationService
    ): int {
        try {
            $jobStatusId = $this->option('job-status');
            if (is_string($jobStatusId) && trim($jobStatusId) !== '') {
                $status = $asyncReferencePackGenerationService->getStatus(trim($jobStatusId));
                if ($status === null) {
                    $this->components->error('Reference pack generation job was not found.');
                    return self::FAILURE;
                }

                $this->components->info('Fetched reference pack generation job status');
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $prompt = trim((string) ($this->argument('prompt') ?? ''));
            if ($prompt === '') {
                $this->components->error('Prompt is required unless --job-status is used.');
                return self::FAILURE;
            }

            $options = $this->buildOptions();
            $userId = $this->option('user-id');
            $workflow = $referencePackGenerationService->prepareWorkflow(
                $prompt,
                $options,
                is_string($userId) ? $userId : null
            );

            $this->components->info('Prepared reference pack workflow');
            $this->line(json_encode([
                'entity_type' => $options['entity_type'],
                'look_mode' => $workflow[0]['look_mode'] ?? ($options['look_mode'] ?? null),
                'look_count' => count(array_values(array_unique(array_map(
                    static fn (array $step): int => (int) ($step['look_index'] ?? 0),
                    $workflow
                )))),
                'frames_per_look' => $this->resolveFramesPerLook($workflow),
                'selected_look_ids' => $this->resolveSelectedLookIds($workflow, $options),
                'requested_views' => count($workflow),
                'steps' => array_map(static fn (array $step): array => [
                    'step' => $step['step'],
                    'look_mode' => $step['look_mode'] ?? null,
                    'look_index' => $step['look_index'] ?? null,
                    'look_variant' => $step['look_variant'] ?? null,
                    'view' => $step['view'],
                    'label' => $step['label'],
                    'reuses_base_preview' => $step['reuses_base_preview'] ?? false,
                    'model' => $step['model'],
                    'parameters' => $step['parameters'],
                ], $workflow),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ((bool) $this->option('dry-run')) {
                $this->components->info('Dry run complete.');
                return self::SUCCESS;
            }

            if (!(bool) $this->option('sync')) {
                $submitted = $asyncReferencePackGenerationService->submit(
                    $prompt,
                    $options,
                    is_string($userId) ? $userId : null
                );

                $this->components->info('Reference pack workflow queued');
                $this->line(json_encode([
                    'job_id' => $submitted['job_id'],
                    'status' => $submitted['status']['status'] ?? 'queued',
                    'job' => $submitted['status'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                if (!(bool) $this->option('wait')) {
                    return self::SUCCESS;
                }

                $status = $asyncReferencePackGenerationService->waitForCompletion(
                    $submitted['job_id'],
                    max(1, (int) $this->option('timeout')),
                    max(1, (int) $this->option('poll-interval'))
                );

                $this->components->info('Reference pack workflow finished');
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return ($status['status'] ?? null) === 'completed' ? self::SUCCESS : self::FAILURE;
            }

            $this->components->info('Submitting reference pack workflow');
            $result = $referencePackGenerationService->generateAndStore(
                $prompt,
                $options,
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
            $referencePack = $result['reference_pack'] ?? $result['character'];

            $this->components->info("Reference pack saved as '{$alias}'");
            $this->table(
                ['Field', 'Value'],
                [
                    ['name', $referencePack['name']],
                    ['alias', $alias],
                    ['frontal_image_url', $referencePack['frontal_image_url']],
                    ['reference_image_urls', implode("\n", $referencePack['reference_image_urls']) ?: '(none)'],
                ]
            );

            $this->line('Next step:');
            if (($options['preview_only'] ?? false) === true) {
                $targetCount = max(2, (int) ($options['frame_count'] ?? 4));
                $this->line("php artisan ai:generate-reference-pack \"{$prompt}\" --entity-type={$options['entity_type']} --from-reference-pack={$alias} --frame-count={$targetCount} --look-size=" . (int) ($options['look_size'] ?? 4));
            } else {
                $this->line('Inject the saved alias into your own workflow service or reuse it through the existing FAL media helpers.');
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
            'entity_type' => trim((string) $this->option('entity-type')) ?: 'character',
            'name' => $this->option('name'),
            'save_as' => $this->option('save-as'),
            'from_reference_pack' => $this->option('from-reference-pack'),
            'frame_count' => max(1, (int) $this->option('frame-count')),
            'look_size' => max(1, (int) $this->option('look-size')),
            'preview_only' => (bool) $this->option('preview-only'),
        ];

        $lookId = $this->option('look-id');
        if (is_string($lookId) && trim($lookId) !== '') {
            $options['look_id'] = trim($lookId);
        }

        $lookPayload = $this->option('look-payload');
        if (is_string($lookPayload) && trim($lookPayload) !== '') {
            $decoded = json_decode($lookPayload, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('--look-payload must be valid JSON object or array.');
            }

            $options['look_payload'] = $decoded;
        }

        $lookSet = $this->option('look-set');
        if (is_string($lookSet) && trim($lookSet) !== '') {
            $decoded = json_decode($lookSet, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('--look-set must be valid JSON array.');
            }

            $options['selected_looks'] = $decoded;
        }

        $lookMode = $this->option('look-mode');
        if (is_string($lookMode) && trim($lookMode) !== '') {
            $options['look_mode'] = trim($lookMode);
        }

        if ((bool) $this->option('strict-stored-looks')) {
            $options['strict_stored_looks'] = true;
        }

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

        return $options;
    }

    private function resolveFramesPerLook(array $workflow): int
    {
        $counts = [];

        foreach ($workflow as $step) {
            if (!is_array($step)) {
                continue;
            }

            $lookIndex = (int) ($step['look_index'] ?? 0);
            if ($lookIndex < 1) {
                continue;
            }

            $counts[$lookIndex] = ($counts[$lookIndex] ?? 0) + 1;
        }

        return $counts === [] ? 0 : max($counts);
    }

    private function resolveSelectedLookIds(array $workflow, array $options): array
    {
        $stepSelectedLooks = $workflow[0]['selected_looks'] ?? null;
        if (is_array($stepSelectedLooks) && $stepSelectedLooks !== []) {
            return array_values(array_filter(array_map(
                static fn (array $look): ?string => isset($look['id']) && is_string($look['id']) ? $look['id'] : null,
                $stepSelectedLooks
            )));
        }

        $selectedLooks = $options['selected_looks'] ?? null;
        if (is_array($selectedLooks) && $selectedLooks !== []) {
            return array_values(array_filter(array_map(
                static fn (array $look): ?string => isset($look['id']) && is_string($look['id']) ? $look['id'] : null,
                $selectedLooks
            )));
        }

        $lookId = $options['look_id'] ?? null;

        return is_string($lookId) && trim($lookId) !== '' ? [trim($lookId)] : [];
    }
}
