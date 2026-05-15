<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Engine Manifest Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        h1, h2 { margin-bottom: 8px; }
        .muted { color: #6b7280; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-bottom: 14px; background: #fff; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; }
        .row > * { flex: 1 1 220px; }
        input, select { width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; }
        button { padding: 8px 12px; border: 1px solid #111827; background: #111827; color: #fff; border-radius: 6px; cursor: pointer; }
        button.secondary { background: #fff; color: #111827; }
        ul { padding-left: 20px; margin: 6px 0; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        .status { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 10px; white-space: pre-wrap; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 10px; white-space: pre-wrap; }
        .entry { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
    </style>
</head>
<body>
    <h1>Manifest Manager</h1>
    <p class="muted">
        <a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a>
        | <a href="{{ route('ai-engine.admin.nodes') }}">Nodes</a>
        | <a href="{{ route('ai-engine.admin.health') }}">Health</a>
        | <a href="{{ route('ai-engine.admin.policies') }}">Policies</a>
        | <code>{{ $manifest_path }}</code>
    </p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error">{{ implode("\n", $errors->all()) }}</div>
    @endif

    <div class="card">
        <h2>Import / Export</h2>
        <p><a href="{{ route('ai-engine.admin.manifest.export') }}">Download manifest JSON</a></p>
        <form method="POST" action="{{ route('ai-engine.admin.manifest.import') }}">
            @csrf
            <label>Import JSON payload</label>
            <textarea name="payload" rows="10" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;" placeholder='{"model_configs":[],"tools":{},"filters":{}}'></textarea>
            <p style="margin-top: 10px;"><button type="submit">Import Manifest</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Add / Update Manifest Entry</h2>
        <form method="POST" action="{{ route('ai-engine.admin.manifest.store') }}">
            @csrf
            <div class="row">
                <div>
                    <label>Type</label>
                    <select name="type" required>
                        <option value="agent">agent (model config)</option>
                        <option value="tool">tool</option>
                        <option value="filter">filter</option>
                    </select>
                </div>
                <div>
                    <label>Key (required for tool/filter)</label>
                    <input name="key" placeholder="invoice or tenant_scope">
                </div>
                <div>
                    <label>Class</label>
                    <input name="class" required placeholder="App\\AI\\Configs\\InvoiceConfig">
                </div>
            </div>
            <p style="margin-top: 10px;"><button type="submit">Save Entry</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Scaffold Class</h2>
        <form method="POST" action="{{ route('ai-engine.admin.manifest.scaffold') }}">
            @csrf
            <div class="row">
                <div>
                    <label>Type</label>
                    <select name="type" required>
                        <option value="agent">agent</option>
                        <option value="tool">tool</option>
                        <option value="filter">filter</option>
                    </select>
                </div>
                <div>
                    <label>Name</label>
                    <input name="name" required placeholder="Invoice">
                </div>
                <div>
                    <label>Model (optional)</label>
                    <input name="model" placeholder="App\\Models\\Invoice">
                </div>
                <div>
                    <label>Description (optional)</label>
                    <input name="description" placeholder="Invoice helper">
                </div>
            </div>
            <p style="margin-top: 10px;"><button type="submit">Scaffold</button></p>
        </form>
    </div>

    <div class="card">
        <h2>Model Configs</h2>
        @forelse ($manifest['model_configs'] as $class)
            <div class="entry">
                <form method="POST" action="{{ route('ai-engine.admin.manifest.update') }}" style="display:flex; gap:8px; flex:1 1 auto;">
                    @csrf
                    <input type="hidden" name="type" value="agent">
                    <input type="hidden" name="old_class" value="{{ $class }}">
                    <input name="class" value="{{ $class }}" required>
                    <button type="submit">Update</button>
                </form>
                <form method="POST" action="{{ route('ai-engine.admin.manifest.destroy') }}">
                    @csrf
                    <input type="hidden" name="type" value="agent">
                    <input type="hidden" name="class" value="{{ $class }}">
                    <button type="submit" class="secondary">Remove</button>
                </form>
            </div>
        @empty
            <p class="muted">No model configs yet.</p>
        @endforelse
    </div>

    @foreach (['tools' => 'Tools', 'filters' => 'Filters'] as $section => $title)
        <div class="card">
            <h2>{{ $title }}</h2>
            @forelse ($manifest[$section] as $key => $class)
                <div class="entry">
                    <form method="POST" action="{{ route('ai-engine.admin.manifest.update') }}" style="display:flex; gap:8px; flex:1 1 auto;">
                        @csrf
                        <input type="hidden" name="type" value="{{ rtrim($section, 's') }}">
                        <input type="hidden" name="old_key" value="{{ $key }}">
                        <input name="key" value="{{ $key }}" required>
                        <input name="class" value="{{ $class }}" required>
                        <button type="submit">Update</button>
                    </form>
                    <form method="POST" action="{{ route('ai-engine.admin.manifest.destroy') }}">
                        @csrf
                        <input type="hidden" name="type" value="{{ rtrim($section, 's') }}">
                        <input type="hidden" name="key" value="{{ $key }}">
                        <button type="submit" class="secondary">Remove</button>
                    </form>
                </div>
            @empty
                <p class="muted">No entries yet.</p>
            @endforelse
        </div>
    @endforeach
</body>
</html>
