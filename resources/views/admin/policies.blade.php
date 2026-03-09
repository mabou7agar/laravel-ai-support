<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Engine Policies</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; text-align: left; padding: 8px; font-size: 14px; }
        select, button { padding: 6px; }
        .muted { color: #6b7280; }
        .status { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
    </style>
</head>
<body>
    <h1>Prompt Policies</h1>
    <p class="muted">
        <a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a>
        | <a href="{{ route('ai-engine.admin.manifest.index') }}">Manifest</a>
        | <a href="{{ route('ai-engine.admin.nodes') }}">Nodes</a>
        | <a href="{{ route('ai-engine.admin.health') }}">Health</a>
    </p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error">{{ implode("\n", $errors->all()) }}</div>
    @endif

    @if(!$store_available)
        <div class="card muted">Policy store is unavailable (table/config missing).</div>
    @endif

    <div class="card">
        <h2>Create Policy Version</h2>
        <form method="POST" action="{{ route('ai-engine.admin.policies.create') }}">
            @csrf
            <p>
                <label>Policy key</label><br>
                <input name="policy_key" value="{{ old('policy_key', $default_policy_key ?? 'decision') }}" style="width: 100%; padding: 8px;">
            </p>
            <p>
                <label>Name</label><br>
                <input name="name" value="{{ old('name') }}" style="width: 100%; padding: 8px;">
            </p>
            <p>
                <label>Template</label><br>
                <textarea name="template" rows="8" style="width: 100%; padding: 8px;" required>{{ old('template') }}</textarea>
            </p>
            <p>
                <label>Status</label><br>
                <select name="status">
                    <option value="draft">draft</option>
                    <option value="active">active</option>
                    <option value="canary">canary</option>
                    <option value="shadow">shadow</option>
                </select>
                <label style="margin-left: 12px;">Rollout %</label>
                <input type="number" name="rollout_percentage" min="0" max="100" value="{{ old('rollout_percentage', 0) }}" style="width: 90px;">
            </p>
            <p>
                <label>Target context (optional)</label><br>
                <input name="tenant_id" value="{{ old('tenant_id') }}" placeholder="tenant_id" style="width: 24%; padding: 8px;">
                <input name="app_id" value="{{ old('app_id') }}" placeholder="app_id" style="width: 24%; padding: 8px;">
                <input name="domain" value="{{ old('domain') }}" placeholder="domain" style="width: 24%; padding: 8px;">
                <input name="locale" value="{{ old('locale') }}" placeholder="locale" style="width: 24%; padding: 8px;">
            </p>
            <p><button type="submit">Create Policy</button></p>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Policy</th>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Scope</th>
                    <th>Activate As</th>
                </tr>
            </thead>
            <tbody>
            @forelse($policies as $policy)
                <tr>
                    <td>{{ $policy->id }}</td>
                    <td>{{ $policy->policy_key }}</td>
                    <td>{{ $policy->version }}</td>
                    <td>{{ $policy->status }}</td>
                    <td>{{ $policy->scope_key }}</td>
                    <td>
                        <form method="POST" action="{{ route('ai-engine.admin.policies.activate') }}">
                            @csrf
                            <input type="hidden" name="policy_id" value="{{ $policy->id }}">
                            <select name="status">
                                <option value="active">active</option>
                                <option value="canary">canary</option>
                                <option value="shadow">shadow</option>
                            </select>
                            <button type="submit">Apply</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No policies found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
