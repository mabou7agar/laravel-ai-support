<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Agent\Runtime\AgentRuntimeCapabilityService;

class AgentRuntimeCapabilitiesCommand extends Command
{
    protected $signature = 'ai-engine:runtime-capabilities
                            {--json : Print JSON report}';

    protected $description = 'Inspect configured agent runtime capabilities';

    public function handle(AgentRuntimeCapabilityService $capabilities): int
    {
        $report = $capabilities->report();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Agent runtime capabilities');
        $this->line('Current runtime: ' . ($report['current']['runtime'] ?? 'unknown'));

        foreach ($report['available'] as $runtime => $runtimeCapabilities) {
            $this->newLine();
            $this->line("Runtime: {$runtime}");
            $this->table(
                ['Capability', 'Supported'],
                collect($runtimeCapabilities)
                    ->except('metadata')
                    ->map(fn (mixed $supported, string $capability): array => [
                        $capability,
                        $supported ? 'yes' : 'no',
                    ])
                    ->values()
                    ->all()
            );
        }

        return self::SUCCESS;
    }
}
