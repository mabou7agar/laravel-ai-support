<?php

namespace LaravelAIEngine\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use LaravelAIEngine\Services\Agent\AgentManifestService;

class InitAgentWorkspaceCommand extends Command
{
    protected $signature = 'ai-engine:init
                            {--force : Overwrite generated files when they already exist}
                            {--dry-run : Show planned changes without writing files}
                            {--no-manifest : Create directories only and skip manifest file}';

    protected $description = 'Initialize AI agent workspace (directories + manifest) with a clean default layout';

    public function handle(AgentManifestService $manifestService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $directories = [
            app_path('AI'),
            app_path('AI/Configs'),
            app_path('AI/Collectors'),
            app_path('AI/Filters'),
            app_path('AI/Skills'),
            app_path('AI/Tools'),
        ];

        $manifestPath = $this->normalizePath($manifestService->manifestPath());
        $manifestDir = dirname($manifestPath);
        if (!in_array($manifestDir, $directories, true)) {
            $directories[] = $manifestDir;
        }

        $createdDirs = 0;
        $existingDirs = 0;

        foreach ($directories as $directory) {
            if (is_dir($directory)) {
                $existingDirs++;
                $this->line("[exists] {$directory}");
                continue;
            }

            if ($dryRun) {
                $this->line("[create] {$directory}");
                $createdDirs++;
                continue;
            }

            File::ensureDirectoryExists($directory);
            $this->line("[created] {$directory}");
            $createdDirs++;
        }

        $manifestStatus = 'skipped';

        if (!$this->option('no-manifest')) {
            $manifestStatus = $this->initializeManifest($manifestPath, $force, $dryRun);
            if ($manifestStatus === 'created' || $manifestStatus === 'updated') {
                $manifestService->refresh();
            }
        }

        $this->newLine();
        $this->info('AI Engine initialization complete.');
        $this->line("- directories created: {$createdDirs}");
        $this->line("- directories already present: {$existingDirs}");
        $this->line("- manifest: {$manifestStatus}");

        $this->newLine();
        $this->line('Suggested next steps:');
        $this->line('1) php artisan ai-engine:scaffold agent Invoice --model="App\\Models\\Invoice"');
        $this->line('2) php artisan ai-engine:scaffold filter TenantScope');
        $this->line('3) php artisan ai-engine:scaffold tool LookupCustomer');
        $this->line('4) php artisan ai-engine:scaffold skill CreateInvoice');

        return self::SUCCESS;
    }

    protected function initializeManifest(string $manifestPath, bool $force, bool $dryRun): string
    {
        $exists = is_file($manifestPath);

        if ($exists && !$force) {
            $this->line("[exists] {$manifestPath}");
            return 'kept';
        }

        $label = $exists ? 'updated' : 'created';
        if ($dryRun) {
            $dryLabel = $exists ? 'update' : 'create';
            $this->line("[{$dryLabel}] {$manifestPath}");
            return $exists ? 'would_update' : 'would_create';
        }

        File::put($manifestPath, $this->defaultManifestContent());
        $this->line("[{$label}] {$manifestPath}");

        return $label;
    }

    protected function defaultManifestContent(): string
    {
        return <<<'PHP'
<?php

return [
    'model_configs' => [
        // App\AI\Configs\RecordConfig::class,
    ],

    'collectors' => [
        // 'record' => App\AI\Collectors\RecordCollector::class,
    ],

    'tools' => [
        // 'lookup_record' => App\AI\Tools\LookupRecordTool::class,
    ],

    'filters' => [
        // 'tenant_scope' => App\AI\Filters\TenantScopeFilter::class,
    ],

    'skill_providers' => [
        // 'create_record' => App\AI\Skills\CreateRecordSkill::class,
    ],

    'skills' => [
        // 'create_record' => [
        //     'name' => 'Create Record',
        //     'description' => 'Create records through approved actions and tools.',
        //     'triggers' => ['create record', 'new record'],
        //     'actions' => ['records.create'],
        //     'requires_confirmation' => true,
        //     'enabled' => false,
        // ],
    ],
];
PHP;
    }

    protected function normalizePath(string $path): string
    {
        if ($path === '') {
            return app_path('AI/agent-manifest.php');
        }

        if ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\/]/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }
}
