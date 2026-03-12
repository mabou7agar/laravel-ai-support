<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $local_node['name'] }} Admin</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        h1 { margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 20px 0; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #fff; }
        .label { font-size: 12px; color: #6b7280; margin-bottom: 4px; }
        .value { font-size: 18px; font-weight: 600; }
        code { background: #f3f4f6; padding: 2px 5px; border-radius: 4px; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
    <h1>{{ $local_node['name'] }} Admin</h1>
    <p class="muted">
        app: {{ $app_name }}
        | slug: {{ $local_node['slug'] }}
        | role: {{ $local_node['role'] }}
        | type: {{ $local_node['type'] }}
        | user: {{ $user_email }}
        | ip: {{ $client_ip }}
    </p>
    <p>
        <a href="{{ route('ai-engine.admin.manifest.index') }}">Manifest Manager</a>
        | <a href="{{ route('ai-engine.admin.nodes') }}">Nodes</a>
        | <a href="{{ route('ai-engine.admin.health') }}">Health</a>
        | <a href="{{ route('ai-engine.admin.policies') }}">Policies</a>
    </p>

    <div class="grid">
        <div class="card">
            <div class="label">Model Configs</div>
            <div class="value">{{ $counts['model_configs'] }}</div>
        </div>
        <div class="card">
            <div class="label">Collectors</div>
            <div class="value">{{ $counts['collectors'] }}</div>
        </div>
        <div class="card">
            <div class="label">Tools</div>
            <div class="value">{{ $counts['tools'] }}</div>
        </div>
        <div class="card">
            <div class="label">Filters</div>
            <div class="value">{{ $counts['filters'] }}</div>
        </div>
        <div class="card">
            <div class="label">Local Node</div>
            <div class="value">{{ $local_node['slug'] }}</div>
        </div>
        <div class="card">
            <div class="label">Federation Target</div>
            <div class="value" style="font-size: 14px;">{{ $master_target }}</div>
        </div>
    </div>

    <div class="card">
        <div class="label">Manifest</div>
        <div><code>{{ $manifest_path }}</code></div>
        <div class="muted">exists: {{ $manifest_exists ? 'yes' : 'no' }}</div>
    </div>

    <div class="card" style="margin-top: 12px;">
        <div class="label">Node Identity</div>
        <ul>
            <li>Name: <strong>{{ $local_node['name'] }}</strong></li>
            <li>Slug: <strong>{{ $local_node['slug'] }}</strong></li>
            <li>Role: <strong>{{ $local_node['role'] }}</strong></li>
            <li>Type: <strong>{{ $local_node['type'] }}</strong></li>
            <li>Aliases: <strong>{{ count($local_node['aliases']) ? implode(', ', $local_node['aliases']) : 'none' }}</strong></li>
            <li>URL: <code>{{ $local_node['url'] }}</code></li>
        </ul>
    </div>

    <div class="card" style="margin-top: 12px;">
        <div class="label">Runtime Flags</div>
        <ul>
            <li>Node federation: <strong>{{ $node_federation_enabled ? 'enabled' : 'disabled' }}</strong></li>
            <li>Startup health gate: <strong>{{ $startup_health_gate_enabled ? 'enabled' : 'disabled' }}</strong></li>
            <li>Qdrant self-check: <strong>{{ $qdrant_self_check_enabled ? 'enabled' : 'disabled' }}</strong></li>
        </ul>
    </div>
</body>
</html>
