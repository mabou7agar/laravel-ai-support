<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use LaravelAIEngine\Http\Controllers\HealthController;

/*
|--------------------------------------------------------------------------
| AI Engine Health-Check Route
|--------------------------------------------------------------------------
|
| GET /ai-engine/health
|
| Returns a JSON payload describing the operational status of each
| AI provider, vector store, graph store, and memory driver.  Designed
| for use with load-balancer health probes and Kubernetes readiness /
| liveness probes.
|
| Enable / disable via:  ai-engine.admin.health_endpoint_enabled (default: true)
| Middleware stack via:  ai-engine.admin.health_middleware         (default: ['web'])
|
*/

$middleware = config('ai-engine.admin.health_middleware', ['web']);
if (!is_array($middleware)) {
    $middleware = ['web'];
}

$middleware = array_values(array_filter(
    array_map(static fn ($item) => is_string($item) ? trim($item) : '', $middleware),
    static fn (string $item): bool => $item !== ''
));

Route::get('/ai-engine/health', [HealthController::class, 'index'])
    ->middleware($middleware)
    ->name('ai-engine.health');
