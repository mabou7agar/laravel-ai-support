<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Console\Commands\Node\Concerns\RequiresMasterNode;
use LaravelAIEngine\Services\Node\NodeBulkSyncService;
use LaravelAIEngine\Services\Node\NodeRegistryService;

class BulkSyncNodesCommand extends Command
{
    use RequiresMasterNode;

    protected $signature = 'ai:nodes-sync
                            {--file= : Path to node definitions (.php or .json)}
                            {--autofix : Auto-fix common payload issues before planning}
                            {--autofix-strict : Use strict auto-fix mode (safe normalization only)}
                            {--apply : Persist create/update/prune changes}
                            {--prune : Mark missing child nodes as inactive (safe prune)}
                            {--ping : Ping created/updated nodes after apply}
                            {--force : Skip confirmation prompt when applying}
                            {--json : Output summary as JSON}';

    protected $description = 'Safely bulk-sync node registry entries from a file';

    public function handle(NodeRegistryService $registry, NodeBulkSyncService $bulkSync): int
    {
        if (!$this->ensureMasterNode()) {
            return 1;
        }

        $filePath = $this->resolveFilePath($bulkSync);
        if (!$filePath) {
            return 1;
        }

        $autofixEnabled = (bool) $this->option('autofix');
        $autofixStrict = (bool) $this->option('autofix-strict');
        $configuredDefaultStrict = strtolower((string) config('ai-engine.nodes.bulk_sync.autofix_mode', 'smart')) === 'strict';

        if ($autofixStrict && !$autofixEnabled) {
            $this->error('`--autofix-strict` requires `--autofix`.');

            return 1;
        }

        try {
            $rawDefinitions = $bulkSync->loadDefinitionsFromFile($filePath);

            $autofixChanges = [];
            $autofixMode = 'off';
            if ($autofixEnabled) {
                $strictMode = $autofixStrict || $configuredDefaultStrict;
                $autofix = $bulkSync->autoFixPayload($rawDefinitions, $strictMode);
                $rawDefinitions = (array) ($autofix['payload'] ?? []);
                $autofixChanges = (array) ($autofix['changes'] ?? []);
                $autofixMode = (string) ($autofix['mode'] ?? ($strictMode ? 'strict' : 'smart'));
            }

            $normalized = $bulkSync->normalizeDefinitionsWithDiagnostics($rawDefinitions);
            $definitions = $normalized['definitions'] ?? [];
            $invalid = $normalized['invalid'] ?? [];
        } catch (\Throwable $e) {
            $this->error('Failed to load node definitions: ' . $e->getMessage());

            return 1;
        }

        if ($definitions === [] && $invalid === []) {
            $this->warn('No valid node definitions were found.');

            return 0;
        }

        $plan = $bulkSync->buildPlan($definitions);
        $plan['invalid'] = $invalid;
        $this->outputPlan(
            $filePath,
            $bulkSync->summarizePlan($plan, $filePath),
            $plan,
            $autofixChanges ?? [],
            $autofixMode ?? 'off'
        );

        if (!(bool) $this->option('apply')) {
            $this->warn('Dry run only. Re-run with --apply to persist changes.');

            return 0;
        }

        if (!empty($invalid)) {
            $this->error('Cannot apply while payload has invalid rows. Fix them first and re-run.');

            return 1;
        }

        if (!$this->option('force') && !$this->confirm('Apply these changes to ai_nodes table?', true)) {
            $this->info('Bulk sync cancelled.');

            return 0;
        }

        $applied = $bulkSync->applyPlan($plan, (bool) $this->option('prune'));
        $pingResults = [];

        if ($this->option('ping')) {
            $this->info('Pinging changed nodes...');
            $pingResults = $bulkSync->pingTouchedNodes($applied['touched_slugs'] ?? [], $registry);

            foreach ($pingResults as $slug => $ok) {
                $this->line(($ok ? '  ✅ ' : '  ❌ ') . $slug);
            }
        }

        $this->outputApplySummary($applied, $pingResults);

        return 0;
    }

    protected function resolveFilePath(NodeBulkSyncService $bulkSync): ?string
    {
        $path = $bulkSync->resolveDefinitionFile($this->option('file'));
        if ($path !== null) {
            return $path;
        }

        $this->error('No node definition file found.');
        $this->line('Use --file=/absolute/or/relative/path.(php|json)');
        $this->line('Example file payload: {"nodes":[{"name":"Billing","url":"https://billing.example.com"}]}');

        return null;
    }

    protected function outputPlan(
        string $filePath,
        array $summary,
        array $plan,
        array $autofixChanges = [],
        string $autofixMode = 'off'
    ): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'plan' => $summary,
                'autofix_mode' => $autofixMode,
                'autofix_changes' => $autofixChanges,
            ], JSON_PRETTY_PRINT));

            return;
        }

        $this->info('Node sync plan');
        $this->table(
            ['File', 'Create', 'Update', 'Unchanged', 'Invalid', 'Desired'],
            [[
                $filePath,
                $summary['create'] ?? 0,
                $summary['update'] ?? 0,
                $summary['unchanged'] ?? 0,
                $summary['invalid'] ?? 0,
                $summary['desired_slugs'] ?? 0,
            ]]
        );

        if ($autofixChanges !== []) {
            $this->line('Auto-fix mode: ' . $autofixMode);
            $this->line('Auto-fix changes:');
            $this->table(
                ['Row', 'Field', 'Message'],
                array_map(
                    fn (array $row) => [
                        (string) ($row['row'] ?? ''),
                        (string) ($row['field'] ?? ''),
                        (string) ($row['message'] ?? ''),
                    ],
                    array_slice($autofixChanges, 0, 50)
                )
            );
        }

        if (!empty($plan['update'])) {
            $this->line('Updates:');
            $this->table(
                ['Slug', 'Fields'],
                array_map(
                    fn (array $row) => [$row['slug'], implode(', ', array_keys((array) ($row['changes'] ?? [])))],
                    $plan['update']
                )
            );
        }

        if (!empty($plan['invalid'])) {
            $this->line('Invalid rows (skipped):');
            $this->table(
                ['Row', 'Slug', 'Reason', 'Suggestion'],
                array_map(
                    fn (array $row) => [
                        (string) ($row['row'] ?? ''),
                        (string) ($row['slug'] ?? ''),
                        (string) ($row['reason'] ?? 'Unknown'),
                        (string) ($row['suggestion'] ?? ''),
                    ],
                    $plan['invalid']
                )
            );
        }
    }

    protected function outputApplySummary(array $applied, array $pingResults): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'applied' => $applied,
                'ping' => $pingResults,
            ], JSON_PRETTY_PRINT));

            return;
        }

        $this->info('Node sync applied');
        $this->table(
            ['Created', 'Updated', 'Deactivated', 'Touched'],
            [[
                $applied['created'] ?? 0,
                $applied['updated'] ?? 0,
                $applied['deactivated'] ?? 0,
                count((array) ($applied['touched_slugs'] ?? [])),
            ]]
        );
    }
}
