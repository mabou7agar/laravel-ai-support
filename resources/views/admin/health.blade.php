<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Engine Health</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        .ok { color: #065f46; }
        .bad { color: #991b1b; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>Infrastructure Health</h1>
    <p class="muted">
        <a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a>
        | <a href="{{ route('ai-engine.admin.manifest.index') }}">Manifest</a>
        | <a href="{{ route('ai-engine.admin.nodes') }}">Nodes</a>
        | <a href="{{ route('ai-engine.admin.policies') }}">Policies</a>
    </p>

    <div class="card">
        <strong>Status:</strong>
        <span class="{{ ($report['ready'] ?? false) ? 'ok' : 'bad' }}">{{ $report['status'] ?? 'unknown' }}</span>
        | ready={{ ($report['ready'] ?? false) ? 'yes' : 'no' }}
    </div>

    @foreach(($report['checks'] ?? []) as $name => $check)
        <div class="card">
            <strong>{{ $name }}</strong><br>
            required={{ ($check['required'] ?? false) ? 'yes' : 'no' }},
            healthy=<span class="{{ ($check['healthy'] ?? false) ? 'ok' : 'bad' }}">{{ ($check['healthy'] ?? false) ? 'yes' : 'no' }}</span><br>
            <span class="muted">{{ $check['message'] ?? '' }}</span>
        </div>
    @endforeach
</body>
</html>
