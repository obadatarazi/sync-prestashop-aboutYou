<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SyncBridge — Laravel</title>
    <style>
        :root { font-family: system-ui, sans-serif; line-height: 1.5; color: #1a1a1a; background: #f4f4f5; }
        body { margin: 0; padding: 2rem; max-width: 52rem; }
        h1 { font-size: 1.35rem; margin-top: 0; }
        .card { background: #fff; border-radius: 8px; padding: 1.25rem 1.5rem; margin: 1rem 0; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr)); gap: .75rem; }
        .stat { font-size: 1.75rem; font-weight: 600; }
        .muted { color: #52525b; font-size: .875rem; }
        a { color: #2563eb; }
        ul { margin: .5rem 0; padding-left: 1.25rem; }
        code { font-size: .85em; background: #f4f4f5; padding: .1em .35em; border-radius: 4px; }
        .pill { display: inline-block; padding: .15rem .5rem; border-radius: 999px; font-size: .75rem; font-weight: 600; }
        .on { background: #fef3c7; color: #92400e; }
        .off { background: #e4e4e7; color: #3f3f46; }
    </style>
</head>
<body>
    <h1>SyncBridge (Laravel)</h1>
    <p class="muted">API-first deployment. Use the links below instead of the legacy PHP admin panels.</p>

    <div class="card">
        <div class="grid">
            <div>
                <div class="muted">Products</div>
                <div class="stat">{{ number_format($counts['products']) }}</div>
            </div>
            <div>
                <div class="muted">Orders</div>
                <div class="stat">{{ number_format($counts['orders']) }}</div>
            </div>
            <div>
                <div class="muted">Sync runs</div>
                <div class="stat">{{ number_format($counts['sync_runs']) }}</div>
            </div>
        </div>
        <p class="muted" style="margin-bottom:0;margin-top:1rem;">
            <span class="pill {{ $dryRun ? 'on' : 'off' }}">DRY_RUN {{ $dryRun ? 'on' : 'off' }}</span>
            <span class="pill {{ $testMode ? 'on' : 'off' }}" style="margin-left:.5rem;">TEST_MODE {{ $testMode ? 'on' : 'off' }}</span>
        </p>
    </div>

    <div class="card">
        <strong>Quick links</strong>
        <ul>
            <li><a href="{{ $appUrl }}/api/documentation">OpenAPI / Swagger UI</a> — try <code>GET /api/v1/products</code>, <code>POST /api/v1/sync</code>, etc.</li>
            <li>CLI: <code>php artisan syncbridge:run</code> (defaults to <code>status</code>)</li>
        </ul>
    </div>

    <div class="card">
        <strong>API base URLs (from env)</strong>
        <ul class="muted">
            <li>PrestaShop: {{ $psBaseUrl !== '' ? $psBaseUrl : '(not set)' }}</li>
            <li>AboutYou: {{ $ayBaseUrl !== '' ? $ayBaseUrl : '(not set)' }}</li>
        </ul>
    </div>
</body>
</html>
