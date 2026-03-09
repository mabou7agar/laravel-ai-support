<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Engine Nodes</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; }
        .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border-bottom: 1px solid #e5e7eb; text-align: left; padding: 8px; font-size: 14px; }
        input, select, button { padding: 6px; font-size: 13px; }
        .status { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
        .error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 10px; border-radius: 6px; margin-bottom: 10px; }
        .inline { display: inline-block; margin-right: 6px; margin-bottom: 4px; }
        .muted { color: #6b7280; }
        .nowrap { white-space: nowrap; }
        code { background: #f3f4f6; padding: 2px 4px; border-radius: 4px; }
        details summary { cursor: pointer; color: #2563eb; }
        .edit-grid input, .edit-grid select { width: 100%; box-sizing: border-box; }
        .edit-grid { display: grid; grid-template-columns: repeat(6, minmax(100px, 1fr)); gap: 8px; margin-top: 8px; }
        textarea { width: 100%; min-height: 180px; padding: 8px; box-sizing: border-box; font-family: monospace; font-size: 12px; }
        .pill { display: inline-block; padding: 2px 6px; border: 1px solid #d1d5db; border-radius: 999px; margin: 2px; font-size: 12px; }
    </style>
</head>
<body>
    <h1>Nodes</h1>
    <p class="muted">
        <a href="{{ route('ai-engine.admin.dashboard') }}">Dashboard</a>
        | <a href="{{ route('ai-engine.admin.manifest.index') }}">Manifest</a>
        | <a href="{{ route('ai-engine.admin.health') }}">Health</a>
        | <a href="{{ route('ai-engine.admin.policies') }}">Policies</a>
    </p>

    @if (session('status'))
        <div class="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="error">{{ implode("\n", $errors->all()) }}</div>
    @endif

    @if(!$table_exists)
        <div class="card muted">`ai_nodes` table is missing. Run package migrations.</div>
    @endif

    <div class="card">
        <strong>Summary:</strong>
        total={{ $stats['total'] ?? 0 }},
        active={{ $stats['active'] ?? 0 }},
        inactive={{ $stats['inactive'] ?? 0 }},
        error={{ $stats['error'] ?? 0 }},
        healthy={{ $stats['healthy'] ?? 0 }}
    </div>

    <div class="card">
        <h2>Register Node</h2>
        <form method="POST" action="{{ route('ai-engine.admin.nodes.register') }}">
            @csrf
            <p>
                <input name="name" value="{{ old('name') }}" placeholder="Node name" style="width: 22%;" required>
                <input name="slug" value="{{ old('slug') }}" placeholder="Slug (optional)" style="width: 14%;">
                <select name="type" style="width: 11%;">
                    <option value="child">child</option>
                    <option value="master">master</option>
                </select>
                <input name="url" value="{{ old('url') }}" placeholder="https://example.com" style="width: 35%;" required>
                <input name="weight" value="{{ old('weight', 1) }}" type="number" min="1" max="1000" style="width: 10%;">
            </p>
            <p>
                <input name="capabilities" value="{{ old('capabilities', implode(',', $default_capabilities ?? [])) }}" placeholder="search,actions,rag" style="width: 45%;">
                <select name="status" style="width: 12%;">
                    <option value="active" @selected(old('status', 'active') === 'active')>active</option>
                    <option value="inactive" @selected(old('status') === 'inactive')>inactive</option>
                    <option value="error" @selected(old('status') === 'error')>error</option>
                </select>
                <input name="api_key" value="{{ old('api_key') }}" placeholder="API key (optional)" style="width: 26%;">
                <button type="submit" style="width: 14%;">Register</button>
            </p>
            <p>
                <input name="description" value="{{ old('description') }}" placeholder="Description (optional)" style="width: 100%;">
            </p>
        </form>
    </div>

    <div class="card">
        <h2>Bulk Node Operations</h2>
        <form method="POST" action="{{ route('ai-engine.admin.nodes.ping-all') }}">
            @csrf
            <button type="submit">Ping All Nodes</button>
            <span class="muted">Refresh health metadata and response times for all registered nodes.</span>
        </form>
    </div>

    <div class="card">
        <h2>Safe Bulk Sync</h2>
        <p class="muted">Paste JSON definitions and run dry-run first. This can safely update many app nodes in one operation.</p>

        @if (!$is_master_node)
            <div class="muted">Bulk sync is disabled on non-master nodes (`ai-engine.nodes.is_master=false`).</div>
        @else
            <p>
                <a href="{{ route('ai-engine.admin.nodes.bulk-sync.template') }}">Download template</a>
                | <a href="{{ route('ai-engine.admin.nodes.bulk-sync.export') }}">Export current nodes</a>
            </p>
            <form method="POST" action="{{ route('ai-engine.admin.nodes.bulk-sync.preview') }}" enctype="multipart/form-data">
                @csrf
                <p>
                    <input type="file" name="payload_file" accept=".json,.txt">
                    <span class="muted">Optional file upload; pasted JSON takes priority.</span>
                </p>
                <textarea name="payload" placeholder='{"nodes":[{"name":"Billing","slug":"billing","url":"https://billing.example.com"}]}'>{{ old('payload') }}</textarea>
                <p>
                    <label class="inline">
                        <input type="checkbox" name="prune" value="1" @checked((bool) old('prune'))> prune missing child nodes (safe deactivate)
                    </label>
                    <label class="inline">
                        <input type="checkbox" name="ping" value="1" @checked((bool) old('ping'))> ping touched nodes after apply
                    </label>
                    <label class="inline">
                        <input type="hidden" name="autofix_strict" value="0">
                        <input type="checkbox" name="autofix_strict" value="1" @checked((bool) old('autofix_strict', $default_autofix_strict ? '1' : '0'))> strict auto-fix mode
                    </label>
                </p>
                <p>
                    <button type="submit" formaction="{{ route('ai-engine.admin.nodes.bulk-sync.autofix') }}">Auto-fix + Dry Run</button>
                    <button type="submit" formaction="{{ route('ai-engine.admin.nodes.bulk-sync.autofix-download') }}" formtarget="_blank">Auto-fix + Download</button>
                    <button type="submit" formaction="{{ route('ai-engine.admin.nodes.bulk-sync.preview') }}">Dry Run</button>
                    <button type="submit" formaction="{{ route('ai-engine.admin.nodes.bulk-sync.apply') }}" onclick="return confirm('Apply bulk sync changes now?');">Apply</button>
                </p>
            </form>
        @endif

        @if (session('bulk_sync_autofix'))
            @php($autofix = session('bulk_sync_autofix'))
            <div class="card" style="margin-top: 10px;">
                <strong>Auto-fix:</strong> mode={{ data_get($autofix, 'mode', 'smart') }}, applied {{ data_get($autofix, 'total_changes', 0) }} change(s).
                @if(!empty($autofix['changes']))
                    <ul>
                        @foreach($autofix['changes'] as $change)
                            <li>row {{ $change['row'] ?? '' }} → {{ $change['field'] ?? '' }}: {{ $change['message'] ?? '' }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if (session('bulk_sync_preview'))
            @php($preview = session('bulk_sync_preview'))
            <div class="card" style="margin-top: 10px;">
                <strong>Dry Run Plan:</strong>
                create={{ data_get($preview, 'summary.create', 0) }},
                update={{ data_get($preview, 'summary.update', 0) }},
                unchanged={{ data_get($preview, 'summary.unchanged', 0) }},
                invalid={{ data_get($preview, 'summary.invalid', 0) }},
                desired={{ data_get($preview, 'summary.desired_slugs', 0) }}

                @if(!empty($preview['create_slugs']))
                    <p><span class="muted">Will create:</span>
                        @foreach($preview['create_slugs'] as $slug)
                            <span class="pill">{{ $slug }}</span>
                        @endforeach
                    </p>
                @endif

                @if(!empty($preview['update_rows']))
                    <p class="muted">Will update:</p>
                    <ul>
                        @foreach($preview['update_rows'] as $row)
                            <li><code>{{ $row['slug'] }}</code> → {{ implode(', ', $row['fields'] ?? []) }}</li>
                        @endforeach
                    </ul>
                @endif

                @if(!empty($preview['invalid_rows']))
                    <p class="muted">Invalid rows (skipped):</p>
                    <ul>
                        @foreach($preview['invalid_rows'] as $row)
                            <li>
                                row {{ $row['row'] }} @if($row['slug'] !== '')(<code>{{ $row['slug'] }}</code>)@endif → {{ $row['reason'] }}
                                @if(($row['suggestion'] ?? '') !== '')
                                    <span class="muted">| suggestion: {{ $row['suggestion'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if (session('bulk_sync_applied'))
            @php($applied = session('bulk_sync_applied'))
            <div class="card" style="margin-top: 10px;">
                <strong>Applied:</strong>
                created={{ data_get($applied, 'summary.created', 0) }},
                updated={{ data_get($applied, 'summary.updated', 0) }},
                deactivated={{ data_get($applied, 'summary.deactivated', 0) }},
                touched={{ count((array) data_get($applied, 'summary.touched_slugs', [])) }}

                @if(!empty($applied['ping']))
                    <p class="muted">Ping results:</p>
                    <ul>
                        @foreach($applied['ping'] as $slug => $ok)
                            <li><code>{{ $slug }}</code> → {{ $ok ? 'ok' : 'failed' }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>URL</th>
                    <th>Capabilities</th>
                    <th>Resp (ms)</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($nodes as $node)
                <tr>
                    <td class="nowrap"><code>{{ $node->slug }}</code></td>
                    <td>{{ $node->name }}</td>
                    <td>
                        <form method="POST" action="{{ route('ai-engine.admin.nodes.status') }}" class="inline">
                            @csrf
                            <input type="hidden" name="node_id" value="{{ $node->id }}">
                            <select name="status">
                                <option value="active" @selected($node->status === 'active')>active</option>
                                <option value="inactive" @selected($node->status === 'inactive')>inactive</option>
                                <option value="error" @selected($node->status === 'error')>error</option>
                            </select>
                            <button type="submit">Set</button>
                        </form>
                    </td>
                    <td>{{ $node->type }}</td>
                    <td class="nowrap">{{ $node->url }}</td>
                    <td>{{ implode(', ', $node->capabilities ?? []) }}</td>
                    <td>{{ $node->avg_response_time ?? '-' }}</td>
                    <td>{{ optional($node->updated_at)->toDateTimeString() }}</td>
                    <td class="nowrap">
                        <details class="inline">
                            <summary>Edit</summary>
                            <form method="POST" action="{{ route('ai-engine.admin.nodes.update') }}">
                                @csrf
                                <input type="hidden" name="node_id" value="{{ $node->id }}">
                                <div class="edit-grid">
                                    <input name="name" value="{{ $node->name }}" placeholder="Name" required>
                                    <input name="slug" value="{{ $node->slug }}" placeholder="Slug" required>
                                    <select name="type">
                                        <option value="child" @selected($node->type === 'child')>child</option>
                                        <option value="master" @selected($node->type === 'master')>master</option>
                                    </select>
                                    <select name="status">
                                        <option value="active" @selected($node->status === 'active')>active</option>
                                        <option value="inactive" @selected($node->status === 'inactive')>inactive</option>
                                        <option value="error" @selected($node->status === 'error')>error</option>
                                    </select>
                                    <input name="weight" type="number" min="1" max="1000" value="{{ $node->weight ?? 1 }}" placeholder="Weight">
                                    <input name="api_key" placeholder="API key (optional)">
                                </div>
                                <div class="edit-grid">
                                    <input style="grid-column: span 3;" name="url" value="{{ $node->url }}" placeholder="URL" required>
                                    <input style="grid-column: span 2;" name="capabilities" value="{{ implode(',', $node->capabilities ?? []) }}" placeholder="search,actions,rag">
                                    <button type="submit">Save</button>
                                </div>
                                <div style="margin-top: 8px;">
                                    <input style="width: 100%; box-sizing: border-box;" name="description" value="{{ $node->description }}" placeholder="Description (optional)">
                                </div>
                            </form>
                        </details>
                        <form method="POST" action="{{ route('ai-engine.admin.nodes.ping') }}" class="inline">
                            @csrf
                            <input type="hidden" name="node_id" value="{{ $node->id }}">
                            <button type="submit">Ping</button>
                        </form>
                        <form method="POST" action="{{ route('ai-engine.admin.nodes.delete') }}" class="inline">
                            @csrf
                            <input type="hidden" name="node_id" value="{{ $node->id }}">
                            <button type="submit" onclick="return confirm('Remove this node?');">Delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">No nodes found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>
