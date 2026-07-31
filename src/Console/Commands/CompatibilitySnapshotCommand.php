<?php

declare(strict_types=1);

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use LaravelAIEngine\Services\Diagnostics\CompatibilitySnapshotService;
use Throwable;

final class CompatibilitySnapshotCommand extends Command
{
    protected $signature = 'ai:compatibility
                            {--target=3.0 : Compatibility target version}
                            {--json : Print the complete machine-readable snapshot}
                            {--fail-on-deprecated : Return a failure status while deprecated surfaces remain}';

    protected $description = 'Inspect the machine-readable compatibility and deprecation inventory.';

    public function handle(CompatibilitySnapshotService $snapshots): int
    {
        try {
            $snapshot = $snapshots->snapshot((string) $this->option('target'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $deprecated = array_values(array_filter(
            $snapshot['surfaces'],
            static fn (array $surface): bool => $surface['status'] === 'deprecated',
        ));
        $payload = [...$snapshot, 'deprecated_count' => count($deprecated)];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->table(
                ['ID', 'Kind', 'Status', 'Replacement', 'Remove in'],
                array_map(static fn (array $surface): array => [
                    $surface['id'],
                    $surface['kind'],
                    $surface['status'],
                    $surface['replacement'],
                    $surface['remove_in'] ?? '-',
                ], $snapshot['surfaces']),
            );
            $this->line(sprintf(
                'Target %s: %d deprecated surface(s), %d recorded route(s).',
                $snapshot['target_version'],
                count($deprecated),
                count($snapshot['routes']),
            ));
        }

        return (bool) $this->option('fail-on-deprecated') && $deprecated !== []
            ? self::FAILURE
            : self::SUCCESS;
    }
}
