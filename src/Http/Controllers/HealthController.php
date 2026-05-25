<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Support\Infrastructure\PackageHealthCheckService;

class HealthController extends Controller
{
    public function __construct(
        private readonly PackageHealthCheckService $health
    ) {}

    public function index(): JsonResponse
    {
        $report = $this->health->report();

        return response()->json($report, $this->health->statusCode($report));
    }
}
