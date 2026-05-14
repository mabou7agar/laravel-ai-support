<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Runs</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        h1 { margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; margin: 12px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        th { color: #374151; background: #f9fafb; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        input, select { border: 1px solid #d1d5db; border-radius: 6px; padding: 5px 8px; }
        button { border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; cursor: pointer; padding: 5px 8px; }
        .alert { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px; padding: 10px 12px; margin: 12px 0; }
        .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    <p><a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a></p>
    <h1>Agent Runs</h1>
    <p class="muted">Inspect persisted runtime runs, routing decisions, steps, approvals, artifacts, and recovery actions.</p>

    @if(session('status'))
        <div class="alert">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    @unless($table_exists)
        <div class="card">Agent run tables are missing. Run package migrations first.</div>
    @else
        <div class="card">
            <form method="GET" action="{{ route('ai-engine.admin.agent-runs') }}">
                <select name="status">
                    <option value="">All statuses</option>
                    @foreach(\LaravelAIEngine\Models\AIAgentRun::STATUSES as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
                <input name="session_id" value="{{ request('session_id') }}" placeholder="session">
                <input name="tenant_id" value="{{ request('tenant_id') }}" placeholder="tenant">
                <input name="workspace_id" value="{{ request('workspace_id') }}" placeholder="workspace">
                <button type="submit">Filter</button>
            </form>
        </div>

        <div class="card">
            <table>
                <thead><tr><th>UUID</th><th>Status</th><th>Runtime</th><th>Session</th><th>Scope</th><th>Current Step</th><th>Updated</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td><a href="{{ route('ai-engine.admin.agent-runs.show', $run->uuid) }}"><code>{{ $run->uuid }}</code></a></td>
                        <td>{{ $run->status }}</td>
                        <td>{{ $run->runtime }}</td>
                        <td><code>{{ $run->session_id }}</code></td>
                        <td>{{ $run->tenant_id ?: 'none' }} / {{ $run->workspace_id ?: 'none' }}</td>
                        <td>{{ $run->current_step ?: 'none' }}</td>
                        <td>{{ optional($run->updated_at)->toDateTimeString() }}</td>
                        <td><a href="{{ route('ai-engine.admin.agent-runs.show', $run->uuid) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">No agent runs.</td></tr>
                @endforelse
                </tbody>
            </table>
            {{ $runs->links() }}
        </div>
    @endunless
</body>
</html>
