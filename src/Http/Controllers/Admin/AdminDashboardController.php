<?php

namespace LaravelAIEngine\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use LaravelAIEngine\Services\Agent\AgentManifestService;

class AdminDashboardController extends Controller
{
    public function index(Request $request, AgentManifestService $manifestService): View
    {
        $manifestPath = $this->normalizePath($manifestService->manifestPath());

        return view('ai-engine::admin.dashboard', [
            'app_name' => config('app.name', 'Laravel'),
            'manifest_path' => $manifestPath,
            'manifest_exists' => is_file($manifestPath),
            'counts' => [
                'model_configs' => count($manifestService->modelConfigs()),
                'collectors' => count($manifestService->collectors()),
                'tools' => count($manifestService->tools()),
                'filters' => count($manifestService->filters()),
            ],
            'node_federation_enabled' => (bool) config('ai-engine.nodes.enabled', true),
            'startup_health_gate_enabled' => (bool) config('ai-engine.infrastructure.startup_health_gate.enabled', false),
            'qdrant_self_check_enabled' => (bool) config('ai-engine.infrastructure.qdrant_self_check.enabled', false),
            'client_ip' => $request->ip(),
            'user_email' => (string) data_get($request->user(), 'email', 'guest'),
        ]);
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
