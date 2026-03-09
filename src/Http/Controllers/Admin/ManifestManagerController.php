<?php

namespace LaravelAIEngine\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\View\View;
use LaravelAIEngine\Services\Agent\AgentManifestEditorService;

class ManifestManagerController extends Controller
{
    public function index(AgentManifestEditorService $editor): View
    {
        $manifest = $editor->read();

        return view('ai-engine::admin.manifest-manager', [
            'manifest_path' => $editor->manifestPath(),
            'manifest' => $manifest,
        ]);
    }

    public function store(Request $request, AgentManifestEditorService $editor): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:agent,collector,tool,filter',
            'class' => 'required|string|max:255',
            'key' => 'nullable|string|max:120',
        ]);

        $type = (string) $validated['type'];
        $className = (string) $validated['class'];
        $key = trim((string) ($validated['key'] ?? ''));

        $changed = false;

        if ($type === 'agent') {
            $changed = $editor->putModelConfig($className);
        } else {
            if ($key === '') {
                return back()->withErrors(['key' => 'Key is required for mapped entries.'])->withInput();
            }

            $section = match ($type) {
                'collector' => 'collectors',
                'tool' => 'tools',
                'filter' => 'filters',
                default => '',
            };

            $changed = $section !== '' ? $editor->putMappedEntry($section, $key, $className) : false;
        }

        return back()->with('status', $changed ? 'Manifest updated.' : 'No changes applied.');
    }

    public function destroy(Request $request, AgentManifestEditorService $editor): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:agent,collector,tool,filter',
            'class' => 'nullable|string|max:255',
            'key' => 'nullable|string|max:120',
        ]);

        $type = (string) $validated['type'];
        $className = trim((string) ($validated['class'] ?? ''));
        $key = trim((string) ($validated['key'] ?? ''));

        $changed = false;

        if ($type === 'agent') {
            if ($className === '') {
                return back()->withErrors(['class' => 'Class is required for model config removal.']);
            }
            $changed = $editor->removeModelConfig($className);
        } else {
            if ($key === '') {
                return back()->withErrors(['key' => 'Key is required for mapped entry removal.']);
            }

            $section = match ($type) {
                'collector' => 'collectors',
                'tool' => 'tools',
                'filter' => 'filters',
                default => '',
            };

            $changed = $section !== '' ? $editor->removeMappedEntry($section, $key) : false;
        }

        return back()->with('status', $changed ? 'Manifest entry removed.' : 'No matching entry found.');
    }

    public function update(Request $request, AgentManifestEditorService $editor): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:agent,collector,tool,filter',
            'old_class' => 'nullable|string|max:255',
            'class' => 'required|string|max:255',
            'old_key' => 'nullable|string|max:120',
            'key' => 'nullable|string|max:120',
        ]);

        $type = (string) $validated['type'];
        $className = (string) $validated['class'];
        $oldClass = trim((string) ($validated['old_class'] ?? ''));
        $oldKey = trim((string) ($validated['old_key'] ?? ''));
        $key = trim((string) ($validated['key'] ?? ''));

        $changed = false;

        if ($type === 'agent') {
            if ($oldClass === '') {
                return back()->withErrors(['old_class' => 'Old class is required for update.'])->withInput();
            }
            $changed = $editor->replaceModelConfig($oldClass, $className);
        } else {
            if ($oldKey === '' || $key === '') {
                return back()->withErrors(['key' => 'Old key and new key are required for update.'])->withInput();
            }

            $section = match ($type) {
                'collector' => 'collectors',
                'tool' => 'tools',
                'filter' => 'filters',
                default => '',
            };

            $changed = $section !== ''
                ? $editor->replaceMappedEntry($section, $oldKey, $key, $className)
                : false;
        }

        return back()->with('status', $changed ? 'Manifest entry updated.' : 'No changes applied.');
    }

    public function scaffold(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => 'required|string|in:agent,collector,tool,filter',
            'name' => 'required|string|max:120',
            'model' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:500',
            'register' => 'nullable|boolean',
        ]);

        $params = [
            'type' => $validated['type'],
            'name' => $validated['name'],
        ];

        if (!empty($validated['model'])) {
            $params['--model'] = $validated['model'];
        }

        if (!empty($validated['description'])) {
            $params['--description'] = $validated['description'];
        }

        if (!(bool) ($validated['register'] ?? true)) {
            $params['--no-register'] = true;
        }

        $exitCode = Artisan::call('ai-engine:scaffold', $params);
        $output = trim((string) Artisan::output());

        if ($exitCode !== 0) {
            return back()->withErrors([
                'scaffold' => $output !== '' ? $output : 'Scaffold command failed.',
            ])->withInput();
        }

        return back()->with('status', $output !== '' ? $output : 'Scaffold completed.');
    }

    public function export(AgentManifestEditorService $editor): Response
    {
        $manifest = $editor->read();

        return response()->json(
            $manifest,
            200,
            [
                'Content-Disposition' => 'attachment; filename="agent-manifest.json"',
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
    }

    public function import(Request $request, AgentManifestEditorService $editor): RedirectResponse
    {
        $validated = $request->validate([
            'payload' => 'required|string',
        ]);

        $decoded = json_decode((string) $validated['payload'], true);

        if (!is_array($decoded)) {
            return back()->withErrors(['payload' => 'Invalid JSON payload.'])->withInput();
        }

        $editor->replaceAll($decoded);

        return back()->with('status', 'Manifest imported successfully.');
    }
}
