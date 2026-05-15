<?php

declare(strict_types=1);

namespace LaravelAIEngine\Http\Controllers\Node;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaravelAIEngine\Services\Admin\NodeDashboardService;

class NodeDashboardController extends Controller
{
    public function __construct(protected NodeDashboardService $dashboard)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->dashboard->dashboard($request->boolean('details', false))
        );
    }

    public function node(Request $request, string $slug)
    {
        return response()->json($this->dashboard->nodeDetail($slug));
    }

    public function metrics(Request $request)
    {
        $period = $request->input('period', '1h');

        return response()->json([
            'period' => $period,
            'metrics' => [
                'response_times' => [],
                'request_counts' => [],
                'error_rates' => [],
                'cache_hit_rates' => [],
            ],
            'note' => 'Historical metrics require a metrics storage system',
        ]);
    }
}
