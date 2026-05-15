<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Billing\PricingInspectionService;

class PricingAuditCommand extends Command
{
    protected $signature = 'ai:pricing-audit
                            {--fail-on-warning : Return a failure exit code when warnings are present}
                            {--json : Output a JSON payload instead of tables}';

    protected $description = 'Audit configured AI engine rates, input media rates, and pricing warnings';

    public function handle(PricingInspectionService $pricing): int
    {
        $audit = $pricing->audit();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($audit);
        }

        $this->info('AI pricing audit');
        $this->table(['Engine', 'Rate', 'Env', 'Flags'], array_map(
            static fn (string $engine, array $entry): array => [
                $engine,
                (string) $entry['rate'],
                (string) $entry['env'],
                implode(', ', (array) $entry['flags']),
            ],
            array_keys($audit['engine_rates']),
            array_values($audit['engine_rates'])
        ));

        if (!empty($audit['additional_input_unit_rates'])) {
            $this->newLine();
            $this->info('Additional input unit rates');
            foreach ($audit['additional_input_unit_rates'] as $engine => $policy) {
                $this->line($engine.': '.json_encode($policy, JSON_UNESCAPED_SLASHES));
            }
        }

        if (!empty($audit['warnings'])) {
            $this->newLine();
            $this->warn('Warnings');
            foreach ($audit['warnings'] as $warning) {
                $this->line('- '.$warning);
            }
        }

        return $this->exitCode($audit);
    }

    /**
     * @param array<string, mixed> $audit
     */
    private function exitCode(array $audit): int
    {
        return (bool) $this->option('fail-on-warning') && !empty($audit['warnings'])
            ? self::FAILURE
            : self::SUCCESS;
    }
}
