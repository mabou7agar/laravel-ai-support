<?php

namespace LaravelAIEngine\Console\Commands\Node;

use Illuminate\Console\Command;
use LaravelAIEngine\Console\Commands\Node\Concerns\RequiresMasterNode;
use LaravelAIEngine\Models\AINode;

class CleanupNodesCommand extends Command
{
    use RequiresMasterNode;

    protected $signature = 'ai-engine:node-cleanup
                            {--status=error,inactive : Comma-separated statuses to target}
                            {--days=14 : Consider nodes stale after this many days}
                            {--include-master : Include master nodes in cleanup}
                            {--delete : Soft-delete matched nodes instead of marking them inactive}
                            {--apply : Persist cleanup changes}
                            {--force : Skip confirmation prompt when applying}
                            {--json : Output summary as JSON}';

    protected $description = 'Safely clean stale nodes from the registry';

    public function handle(): int
    {
        if (!$this->ensureMasterNode()) {
            return 1;
        }

        $statuses = $this->parseStatuses((string) $this->option('status'));
        if ($statuses === []) {
            $this->error('No valid statuses provided.');

            return 1;
        }

        $days = max(0, (int) $this->option('days'));
        $cutoff = now()->subDays($days);

        $query = AINode::query()
            ->whereIn('status', $statuses)
            ->where(function ($builder) use ($cutoff) {
                $builder
                    ->where('last_ping_at', '<=', $cutoff)
                    ->orWhere(function ($or) use ($cutoff) {
                        $or->whereNull('last_ping_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            });

        if (!(bool) $this->option('include-master')) {
            $query->where('type', '!=', 'master');
        }

        $nodes = $query->orderBy('status')->orderBy('slug')->get();

        $summary = [
            'matched' => $nodes->count(),
            'statuses' => $statuses,
            'days' => $days,
            'cutoff' => $cutoff->toDateTimeString(),
            'apply' => (bool) $this->option('apply'),
            'delete' => (bool) $this->option('delete'),
        ];

        if ($this->option('json')) {
            $this->line(json_encode([
                'summary' => $summary,
                'nodes' => $nodes->map(fn (AINode $node) => [
                    'id' => $node->id,
                    'slug' => $node->slug,
                    'type' => $node->type,
                    'status' => $node->status,
                    'last_ping_at' => optional($node->last_ping_at)->toDateTimeString(),
                    'updated_at' => optional($node->updated_at)->toDateTimeString(),
                ])->values()->all(),
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info('Node cleanup scan');
            $this->table(
                ['Matched', 'Statuses', 'Days', 'Cutoff'],
                [[
                    $summary['matched'],
                    implode(', ', $summary['statuses']),
                    $summary['days'],
                    $summary['cutoff'],
                ]]
            );

            if ($nodes->isNotEmpty()) {
                $this->table(
                    ['Slug', 'Type', 'Status', 'Last Ping', 'Updated'],
                    $nodes->take(50)->map(fn (AINode $node) => [
                        $node->slug,
                        $node->type,
                        $node->status,
                        $node->last_ping_at?->toDateTimeString() ?? 'never',
                        $node->updated_at?->toDateTimeString() ?? 'n/a',
                    ])->all()
                );

                if ($nodes->count() > 50) {
                    $this->warn('Output truncated to first 50 rows.');
                }
            }
        }

        if ($nodes->isEmpty()) {
            $this->info('No stale nodes matched the filter.');

            return 0;
        }

        if (!(bool) $this->option('apply')) {
            $this->warn('Dry run only. Re-run with --apply to persist changes.');

            return 0;
        }

        if (!(bool) $this->option('force') && !$this->confirm('Apply node cleanup changes?', true)) {
            $this->info('Node cleanup cancelled.');

            return 0;
        }

        $result = $this->applyCleanup($nodes->all(), (bool) $this->option('delete'));

        if ($this->option('json')) {
            $this->line(json_encode(['applied' => $result], JSON_PRETTY_PRINT));

            return 0;
        }

        $this->info('Node cleanup applied');
        $this->table(
            ['Deleted', 'Deactivated', 'Unchanged'],
            [[
                $result['deleted'],
                $result['deactivated'],
                $result['unchanged'],
            ]]
        );

        return 0;
    }

    /**
     * @return array<int, string>
     */
    protected function parseStatuses(string $input): array
    {
        $allowed = ['active', 'inactive', 'maintenance', 'error'];
        $items = array_filter(array_map(
            static fn (string $item): string => strtolower(trim($item)),
            explode(',', $input)
        ));

        if ($items === []) {
            return ['error', 'inactive'];
        }

        $valid = array_values(array_intersect($items, $allowed));
        $invalid = array_diff($items, $allowed);

        if ($invalid !== []) {
            $this->warn('Ignoring unsupported statuses: ' . implode(', ', $invalid));
        }

        return array_values(array_unique($valid));
    }

    /**
     * @param array<int, AINode> $nodes
     */
    protected function applyCleanup(array $nodes, bool $delete): array
    {
        $result = [
            'deleted' => 0,
            'deactivated' => 0,
            'unchanged' => 0,
        ];

        foreach ($nodes as $node) {
            if ($delete) {
                $node->delete();
                $result['deleted']++;
                continue;
            }

            if ($node->status === 'inactive') {
                $result['unchanged']++;
                continue;
            }

            $node->update(['status' => 'inactive']);
            $result['deactivated']++;
        }

        return $result;
    }
}
