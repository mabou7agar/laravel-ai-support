<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Billing\PricingInspectionService;

class PricingSimulateCommand extends Command
{
    protected $signature = 'ai:pricing-simulate
                            {engine : Engine slug, for example openai, gemini, fal_ai}
                            {model : Model id, for example gpt-4o or fal-ai/kling-video/o3/standard/image-to-video}
                            {--prompt= : Prompt text used for text/character-based calculations}
                            {--parameters= : JSON object with request parameters such as image_count or image_url}
                            {--user-id= : Optional owner/user id for context only; no credits are deducted}
                            {--json : Output a JSON payload instead of a table}';

    protected $description = 'Dry-run an AI request credit calculation without calling a provider or deducting credits';

    public function handle(PricingInspectionService $pricing): int
    {
        $parameters = $this->decodeParameters((string) ($this->option('parameters') ?? ''));
        if ($parameters === null) {
            $this->error('The --parameters option must be a valid JSON object.');

            return self::FAILURE;
        }

        $simulation = $pricing->simulate(
            (string) $this->argument('engine'),
            (string) $this->argument('model'),
            (string) ($this->option('prompt') ?? ''),
            $parameters,
            $this->option('user-id') !== null ? (string) $this->option('user-id') : null
        );

        if ((bool) $this->option('json')) {
            $this->line(json_encode($simulation, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('AI pricing simulation');
        $this->table(['Field', 'Value'], [
            ['engine', $simulation['engine']],
            ['model', $simulation['model']],
            ['calculation_method', $simulation['calculation_method']],
            ['input_count', (string) $simulation['input_count']],
            ['credit_index', (string) $simulation['credit_index']],
            ['base_engine_credits', (string) $simulation['base_engine_credits']],
            ['additional_input_engine_credits', (string) $simulation['additional_input_engine_credits']],
            ['total_engine_credits', (string) $simulation['total_engine_credits']],
            ['engine_rate', (string) $simulation['engine_rate']],
            ['final_credits', (string) $simulation['final_credits']],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeParameters(string $json): ?array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
    }
}
