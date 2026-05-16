<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ApiLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiLogController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $query = ApiLog::with(['user:id,name,email', 'store:id,name']);

        // Filters
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('store_id')) {
            $query->where('store_id', $request->store_id);
        }

        if ($request->filled('method')) {
            $query->where('method', strtoupper($request->method));
        }

        if ($request->filled('status')) {
            $query->where('response_status', $request->status);
        }

        if ($request->boolean('errors_only')) {
            $query->errors();
        }

        if ($request->boolean('slow_only')) {
            $query->slow($request->input('slow_threshold', 1000));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', "%{$search}%")
                    ->orWhere('route_name', 'like', "%{$search}%")
                    ->orWhere('exception', 'like', "%{$search}%");
            });
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->to);
        }

        $logs = $query->latest()->paginate($request->input('per_page', 25));

        return $this->paginatedResponse($logs);
    }

    public function show(int $id): JsonResponse
    {
        $log = ApiLog::with(['user', 'store'])->findOrFail($id);

        return $this->successResponse($log);
    }

    /**
     * Stats summary of API logs.
     */
    public function stats(Request $request): JsonResponse
    {
        $from = $request->input('from', now()->subDays(7));
        $to = $request->input('to', now());

        $query = ApiLog::whereBetween('created_at', [$from, $to]);

        return $this->successResponse([
            'total_requests' => (clone $query)->count(),
            'total_errors' => (clone $query)->errors()->count(),
            'avg_duration_ms' => (int) (clone $query)->avg('duration_ms'),
            'slow_requests' => (clone $query)->slow()->count(),
            'top_endpoints' => (clone $query)
                ->selectRaw('endpoint, count(*) as count')
                ->groupBy('endpoint')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
            'top_errors' => (clone $query)
                ->errors()
                ->selectRaw('response_status, count(*) as count')
                ->groupBy('response_status')
                ->orderByDesc('count')
                ->get(),
        ]);
    }

    /**
     * Clear old logs (retention).
     */
    public function purge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1',
        ]);

        $deleted = ApiLog::where('created_at', '<', now()->subDays($validated['days']))->delete();

        return $this->successResponse(
            ['deleted_count' => $deleted],
            "Deleted {$deleted} log entries older than {$validated['days']} days"
        );
    }
}
