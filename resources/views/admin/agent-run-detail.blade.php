<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Agent Run {{ $run->uuid }}</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        h1 { margin-bottom: 4px; }
        h2 { margin: 0 0 8px; }
        .muted { color: #6b7280; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 16px 0; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; margin: 12px 0; }
        .label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
        .value { font-size: 16px; font-weight: 600; word-break: break-word; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        th { color: #374151; background: #f9fafb; }
        code, pre { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        pre { overflow: auto; padding: 10px; }
        form { display: inline; }
        input { border: 1px solid #d1d5db; border-radius: 6px; padding: 5px 8px; }
        button { border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; cursor: pointer; padding: 5px 8px; }
        button.primary { background: #111827; border-color: #111827; color: #fff; }
        button.danger { border-color: #fecaca; color: #991b1b; }
        .alert { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px; padding: 10px 12px; margin: 12px 0; }
        .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    <p><a href="{{ route('ai-engine.admin.agent-runs') }}">Agent Runs</a> | <a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a></p>
    <h1>Agent Run</h1>
    <p class="muted"><code>{{ $run->uuid }}</code></p>

    @if(session('status'))
        <div class="alert">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    <div class="grid">
        <div class="card"><div class="label">Status</div><div class="value">{{ $run->status }}</div></div>
        <div class="card"><div class="label">Runtime</div><div class="value">{{ $run->runtime }}</div></div>
        <div class="card"><div class="label">Session</div><div class="value">{{ $run->session_id }}</div></div>
        <div class="card"><div class="label">Scope</div><div class="value">{{ $run->tenant_id ?: 'none' }} / {{ $run->workspace_id ?: 'none' }}</div></div>
        <div class="card"><div class="label">Current Step</div><div class="value">{{ $run->current_step ?: 'none' }}</div></div>
    </div>

    <div class="card">
        <h2>Budget And Retention</h2>
        <table>
            <tbody>
                <tr><th>Credits Used</th><td>{{ data_get($run->metadata, 'credits_used', data_get($run->final_response, 'metadata.credits_used', 'none')) }}</td></tr>
                <tr><th>Tokens Used</th><td>{{ data_get($run->metadata, 'tokens_used', data_get($run->final_response, 'metadata.tokens_used', 'none')) }}</td></tr>
                <tr><th>Estimated Cost</th><td>{{ data_get($run->metadata, 'estimated_cost', data_get($run->final_response, 'metadata.estimated_cost', 'none')) }}</td></tr>
                <tr><th>Retention Days</th><td>{{ config('ai-agent.run_retention.run_days', 'default') }}</td></tr>
                <tr><th>Redaction</th><td>{{ config('ai-agent.run_retention.redact_sensitive_data', false) ? 'enabled' : 'disabled' }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Actions</h2>
        <form method="POST" action="{{ route('ai-engine.admin.agent-runs.resume', $run->uuid) }}">
            @csrf
            <input type="hidden" name="queue" value="1">
            <input name="message" placeholder="resume message">
            <button class="primary" type="submit">Resume</button>
        </form>
        <form method="POST" action="{{ route('ai-engine.admin.agent-runs.retry', $run->uuid) }}">
            @csrf
            <input type="hidden" name="queue" value="1">
            <input name="reason" placeholder="retry reason">
            <button type="submit">Retry</button>
        </form>
        <form method="POST" action="{{ route('ai-engine.admin.agent-runs.cancel', $run->uuid) }}">
            @csrf
            <input name="reason" placeholder="cancel reason">
            <button class="danger" type="submit">Cancel</button>
        </form>
    </div>

    <div class="card">
        <h2>Step Timeline</h2>
        <table>
            <thead><tr><th>#</th><th>Type</th><th>Status</th><th>Action</th><th>Source</th><th>Approvals</th><th>Artifacts</th><th>Error</th></tr></thead>
            <tbody>
            @forelse($run->steps as $step)
                <tr>
                    <td>{{ $step->sequence }}</td>
                    <td>{{ $step->type }}</td>
                    <td>{{ $step->status }}</td>
                    <td>{{ $step->action ?: 'none' }}</td>
                    <td>{{ $step->source ?: 'none' }}</td>
                    <td>{{ $step->relationLoaded('approvals') ? $step->getRelation('approvals')->count() : 0 }}</td>
                    <td>{{ $step->relationLoaded('artifacts') ? $step->getRelation('artifacts')->count() : 0 }}</td>
                    <td>{{ $step->error ?: 'none' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No steps.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Routing Trace</h2>
        <pre>{{ json_encode($run->routing_trace ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>

    <div class="card">
        <h2>RAG Sources And Citations</h2>
        <pre>{{ json_encode(data_get($run->final_response, 'metadata.citations', data_get($run->final_response, 'metadata.sources', [])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>

    <div class="card">
        <h2>Hosted Artifacts</h2>
        <table>
            <thead><tr><th>UUID</th><th>Type</th><th>Name</th><th>Provider</th><th>Step</th></tr></thead>
            <tbody>
            @php($artifacts = $run->steps->flatMap(fn ($step) => $step->relationLoaded('artifacts') ? $step->getRelation('artifacts') : collect())->merge($run->providerToolRuns->flatMap(fn ($toolRun) => $toolRun->relationLoaded('artifacts') ? $toolRun->getRelation('artifacts') : collect()))->unique('id'))
            @forelse($artifacts as $artifact)
                <tr>
                    <td><code>{{ $artifact->uuid }}</code></td>
                    <td>{{ $artifact->artifact_type }}</td>
                    <td>{{ $artifact->name }}</td>
                    <td>{{ $artifact->provider }}</td>
                    <td>{{ $artifact->agent_run_step_id ?: 'none' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No artifacts.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h2>Final Response</h2>
        <pre>{{ json_encode($run->final_response ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </div>
</body>
</html>
