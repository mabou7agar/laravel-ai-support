<?php

namespace LaravelAIEngine\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LaravelAIEngine\Services\Agent\AgentManifestService;

class AdminDashboardController extends Controller
{
    public function index(Request $request, AgentManifestService $manifestService): View
    {
        $manifestPath = $this->normalizePath($manifestService->manifestPath());
        $localNode = $this->localNodeProfile();

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
            'local_node' => $localNode,
            'master_target' => config('ai-engine.nodes.master_url') ?: $localNode['url'],
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

    protected function localNodeProfile(): array
    {
        $name = trim((string) config('ai-engine.nodes.local.name', config('app.name', 'Laravel')));
        $slug = trim((string) config('ai-engine.nodes.local.slug', ''));
        $label = trim((string) config(
            'ai-engine.nodes.local.label',
            (string) config('ai-engine.nodes.local.role', '')
        ));
        $aliases = config('ai-engine.nodes.local.aliases', []);

        $name = $name !== '' ? $name : config('app.name', 'Laravel');
        $slug = $slug !== '' ? $slug : Str::slug($name);
        $slug = $slug !== '' ? $slug : 'local';
        $aliases = is_array($aliases) ? $aliases : [];
        $aliases = array_values(array_unique(array_filter(array_map(
            static fn ($alias) => trim((string) $alias),
            $aliases
        ))));

        return [
            'name' => $name,
            'slug' => $slug,
            'label' => $label !== '' ? $label : (config('ai-engine.nodes.is_master', true) ? 'master' : 'client'),
            'role' => $label !== '' ? $label : (config('ai-engine.nodes.is_master', true) ? 'master' : 'client'),
            'type' => config('ai-engine.nodes.is_master', true) ? 'master' : 'child',
            'aliases' => $aliases,
            'url' => config('app.url'),
        ];
    }
}
