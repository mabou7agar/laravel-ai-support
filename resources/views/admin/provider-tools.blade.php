<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Provider Tools</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        h1 { margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; margin: 12px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px; text-align: left; vertical-align: top; }
        th { color: #374151; background: #f9fafb; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        form { display: inline; }
        button { border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #111827; cursor: pointer; padding: 5px 8px; }
        button.primary { background: #111827; border-color: #111827; color: #fff; }
        button.danger { border-color: #fecaca; color: #991b1b; }
        .alert { border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 8px; padding: 10px 12px; margin: 12px 0; }
        .error { border-color: #fecaca; background: #fef2f2; color: #991b1b; }
    </style>
</head>
<body>
    <p><a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a></p>
    <h1>Provider Tools</h1>
    <p class="muted">API base: <code>{{ $api_base }}</code></p>

    @if(session('status'))
        <div class="alert">{{ session('status') }}</div>
    @endif

    @if($errors->any())
        <div class="alert error">{{ $errors->first() }}</div>
    @endif

    @unless($table_exists)
        <div class="card">Provider tool tables are missing. Run package migrations first.</div>
    @else
        <div class="card">
            <h2>Pending Approvals</h2>
            <table>
                <thead><tr><th>Key</th><th>Provider</th><th>Tool</th><th>Risk</th><th>Status</th><th>Run</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($approvals as $approval)
                    <tr>
                        <td><code>{{ $approval->approval_key }}</code></td>
                        <td>{{ $approval->provider }}</td>
                        <td>{{ $approval->tool_name }}</td>
                        <td>{{ $approval->risk_level }}</td>
                        <td>{{ $approval->status }}</td>
                        <td>{{ $approval->tool_run_id }}</td>
                        <td>
                            @if($approval->status === 'pending')
                                <form method="POST" action="{{ route('ai-engine.admin.provider-tools.approvals.approve') }}">
                                    @csrf
                                    <input type="hidden" name="approval_key" value="{{ $approval->approval_key }}">
                                    <input type="hidden" name="continue" value="1">
                                    <input type="hidden" name="async" value="1">
                                    <button class="primary" type="submit">Approve + Queue</button>
                                </form>
                                <form method="POST" action="{{ route('ai-engine.admin.provider-tools.approvals.reject') }}">
                                    @csrf
                                    <input type="hidden" name="approval_key" value="{{ $approval->approval_key }}">
                                    <button class="danger" type="submit">Reject</button>
                                </form>
                            @else
                                <span class="muted">Resolved</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No approvals.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Recent Runs</h2>
            <table>
                <thead><tr><th>UUID</th><th>Provider</th><th>Model</th><th>Status</th><th>Tools</th><th>Updated</th><th>Actions</th></tr></thead>
                <tbody>
                @forelse($runs as $run)
                    <tr>
                        <td><code>{{ $run->uuid }}</code></td>
                        <td>{{ $run->provider }}</td>
                        <td>{{ $run->ai_model }}</td>
                        <td>{{ $run->status }}</td>
                        <td>{{ implode(', ', (array) $run->tool_names) }}</td>
                        <td>{{ optional($run->updated_at)->toDateTimeString() }}</td>
                        <td>
                            @if(in_array($run->status, ['awaiting_approval', 'paused', 'running'], true))
                                <form method="POST" action="{{ route('ai-engine.admin.provider-tools.runs.continue') }}">
                                    @csrf
                                    <input type="hidden" name="run" value="{{ $run->uuid }}">
                                    <input type="hidden" name="async" value="1">
                                    <button type="submit">Queue Continue</button>
                                </form>
                            @else
                                <span class="muted">No action</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">No runs.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2>Recent Artifacts</h2>
            <table>
                <thead><tr><th>UUID</th><th>Provider</th><th>Type</th><th>Name</th><th>Source</th></tr></thead>
                <tbody>
                @forelse($artifacts as $artifact)
                    <tr>
                        <td><code>{{ $artifact->uuid }}</code></td>
                        <td>{{ $artifact->provider }}</td>
                        <td>{{ $artifact->artifact_type }}</td>
                        <td>{{ $artifact->name }}</td>
                        <td>
                            @if($artifact->uuid)
                                <a href="{{ $api_base }}/artifacts/{{ $artifact->uuid }}/download">{{ $artifact->source_url ?: $artifact->citation_url ?: $artifact->provider_file_id ?: 'Download' }}</a>
                            @else
                                {{ $artifact->source_url ?: $artifact->citation_url ?: $artifact->provider_file_id }}
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No artifacts.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @endunless
</body>
</html>
