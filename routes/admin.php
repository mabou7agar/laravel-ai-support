<?php

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\Admin\AdminDashboardController;
use LaravelAIEngine\Http\Controllers\Admin\AdminOperationsController;
use LaravelAIEngine\Http\Controllers\Admin\ManifestManagerController;
use LaravelAIEngine\Http\Middleware\AdminAccessMiddleware;
use LaravelAIEngine\Http\Middleware\SetRequestLocaleMiddleware;

$configuredMiddleware = config('ai-engine.admin_ui.middleware', ['web']);
if (!is_array($configuredMiddleware)) {
    $configuredMiddleware = ['web'];
}

$middleware = array_values(array_filter(array_merge(
    $configuredMiddleware,
    [SetRequestLocaleMiddleware::class, AdminAccessMiddleware::class]
), static fn ($item) => is_string($item) && trim($item) !== ''));

Route::prefix(config('ai-engine.admin_ui.route_prefix', 'ai-engine/admin'))
    ->middleware($middleware)
    ->name('ai-engine.admin.')
    ->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/nodes', [AdminOperationsController::class, 'nodes'])->name('nodes');
        Route::post('/nodes/register', [AdminOperationsController::class, 'registerNode'])->name('nodes.register');
        Route::post('/nodes/update', [AdminOperationsController::class, 'updateNode'])->name('nodes.update');
        Route::post('/nodes/status', [AdminOperationsController::class, 'updateNodeStatus'])->name('nodes.status');
        Route::post('/nodes/ping', [AdminOperationsController::class, 'pingNode'])->name('nodes.ping');
        Route::post('/nodes/ping-all', [AdminOperationsController::class, 'pingAllNodes'])->name('nodes.ping-all');
        Route::post('/nodes/delete', [AdminOperationsController::class, 'deleteNode'])->name('nodes.delete');
        Route::get('/nodes/bulk-sync/template', [AdminOperationsController::class, 'bulkSyncTemplate'])->name('nodes.bulk-sync.template');
        Route::get('/nodes/bulk-sync/export', [AdminOperationsController::class, 'bulkSyncExport'])->name('nodes.bulk-sync.export');
        Route::post('/nodes/bulk-sync/autofix', [AdminOperationsController::class, 'autoFixBulkSync'])->name('nodes.bulk-sync.autofix');
        Route::post('/nodes/bulk-sync/autofix-download', [AdminOperationsController::class, 'autoFixBulkSyncDownload'])->name('nodes.bulk-sync.autofix-download');
        Route::post('/nodes/bulk-sync/preview', [AdminOperationsController::class, 'previewBulkSync'])->name('nodes.bulk-sync.preview');
        Route::post('/nodes/bulk-sync/apply', [AdminOperationsController::class, 'applyBulkSync'])->name('nodes.bulk-sync.apply');
        Route::get('/health', [AdminOperationsController::class, 'health'])->name('health');
        Route::get('/policies', [AdminOperationsController::class, 'policies'])->name('policies');
        Route::get('/agent-runs', [AdminOperationsController::class, 'agentRuns'])->name('agent-runs');
        Route::get('/agent-runs/{run}', [AdminOperationsController::class, 'agentRunDetail'])->name('agent-runs.show');
        Route::post('/agent-runs/{run}/resume', [AdminOperationsController::class, 'resumeAgentRun'])->name('agent-runs.resume');
        Route::post('/agent-runs/{run}/retry', [AdminOperationsController::class, 'retryAgentRun'])->name('agent-runs.retry');
        Route::post('/agent-runs/{run}/cancel', [AdminOperationsController::class, 'cancelAgentRun'])->name('agent-runs.cancel');
        Route::get('/provider-tools', [AdminOperationsController::class, 'providerTools'])->name('provider-tools');
        Route::post('/provider-tools/approvals/approve', [AdminOperationsController::class, 'approveProviderTool'])->name('provider-tools.approvals.approve');
        Route::post('/provider-tools/approvals/reject', [AdminOperationsController::class, 'rejectProviderTool'])->name('provider-tools.approvals.reject');
        Route::post('/provider-tools/runs/continue', [AdminOperationsController::class, 'continueProviderToolRun'])->name('provider-tools.runs.continue');
        Route::post('/policies/create', [AdminOperationsController::class, 'createPolicy'])->name('policies.create');
        Route::post('/policies/activate', [AdminOperationsController::class, 'activatePolicy'])->name('policies.activate');

        Route::get('/manifest', [ManifestManagerController::class, 'index'])->name('manifest.index');
        Route::get('/manifest/export', [ManifestManagerController::class, 'export'])->name('manifest.export');
        Route::post('/manifest/import', [ManifestManagerController::class, 'import'])->name('manifest.import');
        Route::post('/manifest/entries', [ManifestManagerController::class, 'store'])->name('manifest.store');
        Route::post('/manifest/entries/update', [ManifestManagerController::class, 'update'])->name('manifest.update');
        Route::post('/manifest/entries/delete', [ManifestManagerController::class, 'destroy'])->name('manifest.destroy');
        Route::post('/manifest/scaffold', [ManifestManagerController::class, 'scaffold'])->name('manifest.scaffold');
    });
